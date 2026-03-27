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
 * v3.0.0: итерирует все инстансы из config.xml, для каждого:
 *   - Захватывает per-instance lock (xray_start_<uuid>.lock)
 *   - Запускает xray-core + tun2socks если не запущены
 *   - Ждёт TUN-интерфейс до 5 секунд
 */
function xray_configure_do(bool $verbose = false): void
{
    if (!xray_enabled()) {
        return;
    }

    $cfg       = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;
    if (!$instances) {
        return;
    }

    $xrayBin  = '/usr/local/bin/xray-core';
    $t2sBin   = '/usr/local/tun2socks/tun2socks';
    $daemonLog = '/var/log/xray-core.log';

    if (!file_exists($xrayBin) || !file_exists($t2sBin)) {
        return;
    }

    foreach ($instances->instance as $inst) {
        $inst_uuid = (string)$inst['uuid'];
        if ($inst_uuid === '') {
            continue;
        }

        $xrayConf  = "/usr/local/etc/xray-core/config-{$inst_uuid}.json";
        $xrayPid   = "/var/run/xray_core_{$inst_uuid}.pid";
        $t2sConf   = "/usr/local/tun2socks/config-{$inst_uuid}.yaml";
        $t2sPid    = "/var/run/tun2socks_{$inst_uuid}.pid";
        $lockFile  = "/var/run/xray_start_{$inst_uuid}.lock";
        $tunIface  = (string)($inst->tun_interface ?? 'proxytun2socks0');

        // BUG-1 FIX: per-instance lock без goto. Если lock занят — пропускаем.
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
                if ($verbose) {
                    echo "xray [{$inst_uuid}]: start already in progress (lock held), skipping\n";
                }
                fclose($lockFd);
                $lockFd = false;
            }
        }

        if ($shouldStart) {
            try {
                $alreadyRunning = false;
                if (file_exists($xrayPid)) {
                    $pid = (int)trim(file_get_contents($xrayPid));
                    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
                    $alreadyRunning = ($rc === 0);
                }

                if (!$alreadyRunning) {
                    if ($verbose) {
                        echo "xray [{$inst_uuid}]: starting xray-core and tun2socks...\n";
                    }

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

                    // Ждём TUN до 5 секунд
                    $rc = 1;
                    for ($i = 0; $i < 5; $i++) {
                        exec('/sbin/ifconfig ' . escapeshellarg($tunIface) . ' 2>/dev/null', $o, $rc);
                        if ($rc === 0) {
                            break;
                        }
                        sleep(1);
                    }

                    if ($verbose) {
                        echo "xray [{$inst_uuid}]: TUN {$tunIface} " . ($rc === 0 ? "up" : "not found after 5s") . "\n";
                    }
                }
            } finally {
                // BUG-1 FIX: lock освобождается гарантированно
                if ($lockFd !== false) {
                    flock($lockFd, LOCK_UN);
                    fclose($lockFd);
                    @unlink($lockFile);
                }
            }
        }
    }

    // reload firewall/routing — выполняется всегда
    @include_once('filter.inc');
    @include_once('interfaces.inc');
    @include_once('system.inc');

    if (function_exists('filter_configure_sync')) {
        filter_configure_sync();
    } elseif (function_exists('filter_configure')) {
        filter_configure();
    }

    if (function_exists('system_routing_configure')) {
        system_routing_configure();
    }

    if ($verbose) {
        echo "xray: boot setup complete\n";
    }
}

/**
 * v3.0.0: регистрирует один сервис на каждый инстанс.
 */
function xray_services()
{
    $services  = [];
    $cfg       = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;

    if ($instances) {
        foreach ($instances->instance as $inst) {
            $inst_uuid = (string)$inst['uuid'];
            if ($inst_uuid === '') {
                continue;
            }
            $name       = (string)($inst->name ?? 'default');
            $services[] = [
                'description' => 'Xray (' . $name . ')',
                'configd'     => [
                    'restart' => ['xray restart ' . $inst_uuid],
                    'start'   => ['xray start '   . $inst_uuid],
                    'stop'    => ['xray stop '    . $inst_uuid],
                ],
                'name'    => 'xray',
                'pidfile' => "/var/run/xray_core_{$inst_uuid}.pid",
            ];
        }
    }

    return $services;
}

/**
 * E1: регистрирует cron-задание для watchdog.
 */
function xray_cron(): array
{
    return [
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
