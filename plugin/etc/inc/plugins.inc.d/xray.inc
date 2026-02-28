<?php

function xray_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return isset($cfg->OPNsense->xray->general->enabled)
        && (string)$cfg->OPNsense->xray->general->enabled === '1';
}

function xray_configure(): array
{
    return [10 => ['xray_configure_do']];
}

/**
 * BUG-1 FIX: убрана хрупкая goto-логика управления lock-файлом.
 *
 * До исправления: использовался `goto reload_network` для пропуска запуска если
 * lock занят. Проблема: PHP-`goto` не защищает от исключения между захватом lock
 * и меткой — lock-файл мог остаться заблокированным навсегда, все последующие
 * do_start() видели бы "lock held" и пропускали запуск сервисов.
 *
 * После: явный флаг $lockAcquired + структурированный if/finally — lock
 * гарантированно освобождается при любом пути выполнения. Логика прозрачна.
 */
function xray_configure_do(bool $verbose = false): void
{
    if (!xray_enabled()) {
        return;
    }

    $cfg  = OPNsense\Core\Config::getInstance()->object();
    $inst = $cfg->OPNsense->xray->instance ?? null;
    if (!$inst) {
        return;
    }

    $xrayBin  = '/usr/local/bin/xray-core';
    $xrayConf = '/usr/local/etc/xray-core/config.json';
    $xrayPid  = '/var/run/xray_core.pid';
    $t2sBin   = '/usr/local/tun2socks/tun2socks';
    $t2sConf  = '/usr/local/tun2socks/config.yaml';
    $t2sPid   = '/var/run/tun2socks.pid';
    $lockFile = '/var/run/xray_start.lock';
    $daemonLog = '/var/log/xray-core.log';  // BUG-7: лог вместо /dev/null
    $tunIface = (string)($inst->tun_interface ?? 'proxytun2socks0');

    if (!file_exists($xrayBin) || !file_exists($t2sBin)) {
        return;
    }

    // BUG-1 FIX: lock-логика без goto.
    // Пытаемся захватить lock (non-blocking). Если lock занят — другой процесс
    // (xray-service-control.php) уже запускает сервисы, дублировать не нужно.
    // Флаг $lockAcquired и finally гарантируют освобождение при любом исходе.
    $lockFd       = false;
    $lockAcquired = false;
    $shouldStart  = false;

    $lockFd = fopen($lockFile, 'c');
    if ($lockFd !== false) {
        if (flock($lockFd, LOCK_EX | LOCK_NB)) {
            $lockAcquired = true;
            fwrite($lockFd, (string)getmypid());
            fflush($lockFd);
            $shouldStart = true;
        } else {
            // lock занят — другой процесс уже запускает сервисы
            if ($verbose) {
                echo "xray: start already in progress (lock held), skipping boot hook launch\n";
            }
            fclose($lockFd);
            $lockFd = false;
        }
    }

    if ($shouldStart) {
        try {
            // Проверяем — xray уже запущен?
            $alreadyRunning = false;
            if (file_exists($xrayPid)) {
                $pid = (int)trim(file_get_contents($xrayPid));
                exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
                $alreadyRunning = ($rc === 0);
            }

            if (!$alreadyRunning) {
                if ($verbose) {
                    echo "xray: starting xray-core and tun2socks...\n";
                }

                // BUG-7 FIX: stderr → лог-файл вместо /dev/null
                $log = escapeshellarg($daemonLog);
                exec('/usr/sbin/daemon -p ' . escapeshellarg($xrayPid)
                    . ' ' . escapeshellarg($xrayBin)
                    . ' run -c ' . escapeshellarg($xrayConf)
                    . ' >> ' . $log . ' 2>&1 &');

                sleep(1);

                exec('/usr/sbin/daemon -p ' . escapeshellarg($t2sPid)
                    . ' ' . escapeshellarg($t2sBin)
                    . ' -config ' . escapeshellarg($t2sConf)
                    . ' >> ' . $log . ' 2>&1 &');

                // Ждём появления TUN-интерфейса до 5 секунд
                $rc = 1;
                for ($i = 0; $i < 5; $i++) {
                    exec('/sbin/ifconfig ' . escapeshellarg($tunIface) . ' 2>/dev/null', $o, $rc);
                    if ($rc === 0) {
                        break;
                    }
                    sleep(1);
                }

                if ($verbose) {
                    echo "xray: TUN {$tunIface} " . ($rc === 0 ? "up" : "not found after 5s") . "\n";
                }
            }
        } finally {
            // BUG-1 FIX: lock освобождается гарантированно через finally,
            // даже если в блоке выше произошло исключение или ранний return.
            if ($lockFd !== false) {
                flock($lockFd, LOCK_UN);
                fclose($lockFd);
                @unlink($lockFile);
            }
        }
    }

    // reload firewall/routing — выполняется всегда, независимо от lock
    @include_once('filter.inc');
    @include_once('interfaces.inc');
    @include_once('system.inc');

    if ($verbose) {
        echo "xray: reloading firewall rules...\n";
    }

    if (function_exists('filter_configure_sync')) {
        filter_configure_sync();
    } elseif (function_exists('filter_configure')) {
        filter_configure();
    }

    if ($verbose) {
        echo "xray: reconfiguring routing...\n";
    }

    if (function_exists('system_routing_configure')) {
        system_routing_configure();
    }

    if ($verbose) {
        echo "xray: boot setup complete\n";
    }
}

function xray_services()
{
    $services   = [];
    $services[] = [
        'description' => 'Xray',
        'configd'     => [
            'restart' => ['xray restart'],
            'start'   => ['xray start'],
            'stop'    => ['xray stop'],
        ],
        'name'    => 'xray',
        'pidfile' => '/var/run/xray_core.pid',
    ];
    return $services;
}

/**
 * E1: регистрирует cron-задание для watchdog.
 * OPNsense вызывает xray_cron() и получает список задач для cron-планировщика.
 * Функция возвращает задачу всегда — сам xray-watchdog.php читает watchdog_enabled из config.xml
 * и выходит без действий если он отключён.
 */
function xray_cron(): array
{
    return [
        // Каждую минуту проверяем живость процессов через configd action [watchdog]
        ['xray_cron_watchdog', '/', '*', '*', '*', '*'],
    ];
}

function xray_cron_watchdog(): void
{
    (new OPNsense\Core\Backend())->configdRun('xray watchdog');
}

function xray_xmlrpc_sync()
{
    return [];
}
