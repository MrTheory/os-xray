#!/usr/local/bin/php
<?php
/**
 * Cron IF health (every 5 min): Test all IF for enabled+running instances.
 * If the current ACTIVE_TUN4/6 instance fails the SOCKS IF probe — one-shot
 * reselect active tun2socks (same as Test auto route).
 */

require_once('config.inc');
require_once(__DIR__ . '/xray-boot-log.inc.php');
require_once(__DIR__ . '/xray-if-test.inc.php');
require_once(__DIR__ . '/xray-bird-active-tun.php');

function xray_if_health_curl(string $listen, int $port, int $retries = 3): array
{
    if ($port < 1 || $port > 65535) {
        $port = 10808;
    }
    $proxy = xray_socks_connect_host($listen) . ':' . $port;
    xray_socks_wait_ready($listen, $port, 10);
    $last = [
        'ok'    => false,
        'http'  => 0,
        'proxy' => $proxy,
        'code'  => '0',
    ];
    $tries = max(1, $retries);
    for ($i = 0; $i < $tries; $i++) {
        $out = [];
        $rc = 0;
        exec(
            '/usr/local/bin/curl'
            . ' --socks5 ' . escapeshellarg($proxy)
            . ' -s -L -o /dev/null'
            . ' -w %{http_code}'
            . ' ' . escapeshellarg('https://1.1.1.1')
            . ' --max-time 10',
            $out,
            $rc
        );
        $code = trim(implode('', $out));
        $http = (int)$code;
        $ok   = ($rc === 0 && $http >= 200 && $http < 400);
        $last = [
            'ok'    => $ok,
            'http'  => $http,
            'proxy' => $proxy,
            'code'  => $code !== '' ? $code : '0',
        ];
        if ($ok) {
            return $last;
        }
        if ($http !== 0 || $i + 1 >= $tries) {
            return $last;
        }
        usleep(500000);
        xray_socks_wait_ready($listen, $port, 3);
    }
    return $last;
}

$cfg     = OPNsense\Core\Config::getInstance()->object();
$general = $cfg->OPNsense->xray->general ?? null;
$ins     = $cfg->OPNsense->xray->instances ?? null;

if ((string)($general->enabled ?? '0') !== '1' || !$ins) {
    echo "if health: plugin disabled or no instances\n";
    exit(0);
}

$cands = xray_bird_active_tun_candidates($ins);
if ($cands === []) {
    echo "if health: no enabled running instances\n";
    exit(0);
}

$byTun   = [];
$lines   = [];
$failed  = [];
$ifRows  = [];
foreach ($cands as $c) {
    $byTun[$c['tun']] = $c;
    $p = xray_if_health_curl($c['listen'], $c['port']);
    $status = $p['ok'] ? 'ok' : 'failed';
    $lines[] = "[{$c['name']}] tun={$c['tun']} socks={$p['proxy']} HTTP {$p['code']} {$status}";
    echo $lines[count($lines) - 1] . "\n";
    $ifRows[$c['uuid']] = [
        'ok'        => $p['ok'],
        'http_code' => $p['http'],
    ];
    if (!$p['ok']) {
        $failed[$c['uuid']] = $c;
    }
}
xray_if_test_save_many($ifRows, 'cron');

$act4 = xray_bird_active_tun_current(
    XRAY_BIRD_INC_DIR . '/active_tun_v4.inc',
    'ACTIVE_TUN4_IF'
);
$act6 = xray_bird_active_tun_current(
    XRAY_BIRD_INC_DIR . '/active_tun_v6.inc',
    'ACTIVE_TUN6_IF'
);

$activeFailed = [];
foreach (['v4' => $act4, 'v6' => $act6] as $fam => $tun) {
    if ($tun === '') {
        continue;
    }
    if (!isset($byTun[$tun])) {
        $activeFailed[] = "ACTIVE_TUN{$fam}={$tun} not enabled/running";
        continue;
    }
    $c = $byTun[$tun];
    if (isset($failed[$c['uuid']])) {
        $activeFailed[] = "ACTIVE_TUN{$fam}={$tun} ({$c['name']}) IF failed";
    }
}

$reselected = false;
$selMsg     = '';
$anyOk      = count($failed) < count($cands);
if ($activeFailed !== []) {
    echo "if health: active IF failed — " . implode('; ', $activeFailed) . "\n";
    // All SOCKS probes failed (typical boot race) — do not reshuffle ACTIVE_TUN by weight.
    if (!$anyOk) {
        $selMsg = 'skip reselect — all IF probes failed (SOCKS not ready?)';
        echo "if health: {$selMsg}\n";
    } else {
        echo "if health: reselecting active tun\n";
        $sel = xray_bird_select_and_write_active_tun();
        if (!empty($sel['changed'])) {
            $cfgObj = OPNsense\Core\Config::getInstance()->object();
            $bgpOn  = (string)($cfgObj->OPNsense->xray->general->bgp_enabled ?? '0') === '1';
            if ($bgpOn) {
                xray_bird_active_tun_configure();
                $selMsg = 'active tun changed — birdc configure';
            } else {
                $selMsg = 'active tun changed — BGP off, files only';
            }
            $reselected = true;
        } else {
            $selMsg = 'reselect ran, active tun unchanged';
        }
        echo "if health: {$selMsg}\n";
    }
} else {
    echo "if health: active IF ok (v4={$act4}, v6={$act6})\n";
}

$body = implode("\n", $lines) . "\n"
    . "active_v4={$act4} active_v6={$act6}\n";
if ($activeFailed !== []) {
    $body .= 'active failed: ' . implode('; ', $activeFailed) . "\n"
        . ($selMsg !== '' ? "action: {$selMsg}\n" : '');
} else {
    $body .= "action: none (active IF ok)\n";
}

xray_boot_log_block('IF health (cron)', $body);
echo "RESULT:OK\n";
exit(0);
