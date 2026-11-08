<?php

function xray_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return isset($cfg->OPNsense->xray->general->enabled)
        && (string)$cfg->OPNsense->xray->general->enabled === '1';
}

function xray_configure(): array
{
    return ['bootup' => ['xray_configure_do:10']];
}

/**
 * Load boot helpers (light flag + deferred birdsync).
 */
function xray_boot_helpers_load(): void
{
    $inc = '/usr/local/opnsense/scripts/Xray/xray-boot-log.inc.php';
    if (is_readable($inc)) {
        require_once $inc;
    }
}

/**
 * BUG-1 FIX: убрана хрупкая goto-логика управления lock-файлом.
 *
 * Boot path (proposals 1–5): start daemons only — no SOCKS probes, no sync birdsync,
 * no heavy filter/routing here. One deferred birdsync_postboot after delay.
 */
function xray_bird_sync_exec(): void
{
    $script = '/usr/local/opnsense/scripts/Xray/xray-service-control.php';
    if (!file_exists($script)) {
        return;
    }
    exec('/usr/local/bin/php ' . escapeshellarg($script) . ' birdsync 2>&1');
}

function xray_configure_do(bool $verbose = false): void
{
    xray_boot_helpers_load();

    // Mark light boot so 50-xray / SOCKS probes / start path stay cheap.
    if (function_exists('xray_boot_light_begin')) {
        xray_boot_light_begin();
    }

    if (!xray_enabled()) {
        if (function_exists('xray_bird_sync_deferred')) {
            xray_bird_sync_deferred();
        }
        return;
    }

    $cfg       = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;
    if (!$instances) {
        if (function_exists('xray_bird_sync_deferred')) {
            xray_bird_sync_deferred();
        }
        return;
    }

    $xrayBin  = '/usr/local/bin/xray-core';
    $t2sBin   = '/usr/local/tun2socks/tun2socks';

    if (!file_exists($xrayBin) || !file_exists($t2sBin)) {
        if (function_exists('xray_bird_sync_deferred')) {
            xray_bird_sync_deferred();
        }
        return;
    }

    foreach ($instances->instance as $inst) {
        $inst_uuid = (string)$inst['uuid'];
        if ($inst_uuid === '' || (string)($inst->enabled ?? '1') !== '1') {
            continue;
        }

        $xrayPid   = "/var/run/xray_core_{$inst_uuid}.pid";
        $t2sPid    = "/var/run/tun2socks_{$inst_uuid}.pid";
        $lockFile  = "/var/run/xray_start_{$inst_uuid}.lock";

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
                $xrayRunning = false;
                if (file_exists($xrayPid)) {
                    $pid = (int)trim(file_get_contents($xrayPid));
                    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
                    $xrayRunning = ($rc === 0);
                }
                $t2sRunning = false;
                if (file_exists($t2sPid)) {
                    $pid = (int)trim(file_get_contents($t2sPid));
                    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
                    $t2sRunning = ($rc === 0);
                }

                if (!$xrayRunning || !$t2sRunning) {
                    if ($verbose) {
                        echo "xray [{$inst_uuid}]: starting via xray-service-control.php...\n";
                    }
                    // start_boot: daemons + light TUN setup only (no BGP/SOCKS/birdsync).
                    exec('/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-service-control.php start_boot '
                        . escapeshellarg($inst_uuid) . ' 2>&1', $startOut, $startRc);
                    if ($verbose && !empty($startOut)) {
                        echo implode("\n", $startOut) . "\n";
                    }
                    if ($startRc !== 0 && $verbose) {
                        echo "xray [{$inst_uuid}]: start_boot returned exit {$startRc}\n";
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

    // Do NOT run filter_configure_sync / system_routing_configure / birdsync here —
    // they blocked OPNsense boot for minutes. One deferred postboot job handles them.
    if ($verbose) {
        echo "xray: boot setup complete (deferred birdsync)\n";
    }
    if (function_exists('xray_bird_sync_deferred')) {
        xray_bird_sync_deferred();
    }
}

/**
 * v3.0.0: регистрирует один сервис на каждый инстанс.
 */
function xray_services()
{
    // Do not register services when the plugin is disabled — otherwise OPNsense
    // shows them as stopped and the left menu / services widget stay red.
    if (!xray_enabled()) {
        return [];
    }

    $services  = [];
    $cfg       = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;

    if ($instances) {
        foreach ($instances->instance as $inst) {
            $inst_uuid = (string)$inst['uuid'];
            if ($inst_uuid === '' || (string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $name       = (string)($inst->name ?? '');
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
 * Cron jobs for OPNsense system_cron_configure().
 * Only entries with key "autocron" are written to /var/cron/tabs/root.
 * Scripts self-gate on enabled flags (watchdog / BGP / IF / DNStap / Static).
 */
function xray_cron(): array
{
    $jobs = [];
    // Every minute: watchdog + Auto route tick
    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d xray watchdog', '*'];
    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d xray birdautoroute', '*'];
    // Every 5 minutes: IF health → Test Result store + active TUN reselect if needed
    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d xray ifhealth', '*/5'];
    // Daily URL refreshes
    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d xray dnstapfetch', '15', '4'];
    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d xray staticfetch', '30', '4'];
    return $jobs;
}

function xray_xmlrpc_sync()
{
    return [];
}
