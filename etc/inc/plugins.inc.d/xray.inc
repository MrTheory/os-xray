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
    $tunIface = (string)($inst->tun_interface ?? 'proxytun2socks0');

    if (!file_exists($xrayBin) || !file_exists($t2sBin)) {
        return;
    }

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

        exec('/usr/sbin/daemon -p ' . escapeshellarg($xrayPid)
            . ' ' . escapeshellarg($xrayBin)
            . ' run -c ' . escapeshellarg($xrayConf)
            . ' > /dev/null 2>&1 &');

        sleep(1);

        exec('/usr/sbin/daemon -p ' . escapeshellarg($t2sPid)
            . ' ' . escapeshellarg($t2sBin)
            . ' -config ' . escapeshellarg($t2sConf)
            . ' > /dev/null 2>&1 &');

        // Ждём появления TUN-интерфейса до 5 секунд
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

    // Перезапускаем firewall и routing — то же что делает кнопка Apply.
    // Подключаем нужные файлы явно на случай раннего boot.
    @include_once('filter.inc');
    @include_once('interfaces.inc');
    @include_once('system.inc');

    if ($verbose) {
        echo "xray: reloading firewall rules...\n";
    }

    // Перезагружаем pf-правила — без этого трафик через TUN заблокирован
    if (function_exists('filter_configure_sync')) {
        filter_configure_sync();
    } elseif (function_exists('filter_configure')) {
        filter_configure();
    }

    if ($verbose) {
        echo "xray: reconfiguring routing...\n";
    }

    // Пересчитываем маршруты — без этого gateway на TUN не активен
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

function xray_xmlrpc_sync()
{
    return [];
}
