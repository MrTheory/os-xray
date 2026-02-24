#!/usr/local/bin/php
<?php

require_once('config.inc');

define('XRAY_BIN',      '/usr/local/bin/xray-core');
define('XRAY_CONF',     '/usr/local/etc/xray-core/config.json');
define('XRAY_CONF_DIR', '/usr/local/etc/xray-core');
define('XRAY_PID',      '/var/run/xray_core.pid');
define('T2S_BIN',       '/usr/local/tun2socks/tun2socks');
define('T2S_CONF',      '/usr/local/tun2socks/config.yaml');
define('T2S_CONF_DIR',  '/usr/local/tun2socks');
define('T2S_PID',       '/var/run/tun2socks.pid');

// ─── Read config from OPNsense config.xml ────────────────────────────────────
function xray_get_config(): array
{
    $cfg  = OPNsense\Core\Config::getInstance()->object();
    $node = $cfg->OPNsense->xray ?? null;
    if (!$node) {
        return [];
    }

    $g    = $node->general  ?? null;
    $inst = $node->instance ?? null;

    return [
        'enabled'      => (string)($g->enabled ?? '0') === '1',
        'server'       => (string)($inst->server_address      ?? ''),
        'port'         => (int)(string)($inst->server_port    ?? 443),
        'uuid'         => (string)($inst->uuid                ?? ''),
        'flow'         => (string)($inst->flow                ?? 'xtls-rprx-vision'),
        'sni'          => (string)($inst->reality_sni         ?? ''),
        'pubkey'       => (string)($inst->reality_pubkey      ?? ''),
        'shortid'      => (string)($inst->reality_shortid     ?? ''),
        'fingerprint'  => (string)($inst->reality_fingerprint ?? 'chrome'),
        'socks5_port'  => (int)(string)($inst->socks5_port    ?? 10808),
        'tun_iface'    => (string)($inst->tun_interface       ?? 'proxytun2socks0'),
        'mtu'          => (int)(string)($inst->mtu            ?? 1500),
        'loglevel'     => (function($v){ return $v==='e'?'error':($v?:'warning'); })((string)($inst->loglevel ?? 'warning')),
    ];
}

// ─── Write xray config.json ───────────────────────────────────────────────────
function xray_write_config(array $c): void
{
    $flow = ($c['flow'] === 'none' || $c['flow'] === '') ? '' : $c['flow'];

    $cfg = [
        'log'      => ['loglevel' => $c['loglevel'] ?: 'warning'],
        'inbounds' => [[
            'tag'      => 'socks-in',
            'port'     => $c['socks5_port'],
            'listen'   => '127.0.0.1',
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => '127.0.0.1'],
        ]],
        'outbounds' => [
            [
                'tag'      => 'proxy',
                'protocol' => 'vless',
                'settings' => [
                    'vnext' => [[
                        'address' => $c['server'],
                        'port'    => $c['port'],
                        'users'   => [[
                            'id'         => $c['uuid'],
                            'encryption' => 'none',
                            'flow'       => $flow,
                        ]],
                    ]],
                ],
                'streamSettings' => [
                    'network'         => 'tcp',
                    'security'        => 'reality',
                    'realitySettings' => [
                        'serverName'  => $c['sni'],
                        'fingerprint' => $c['fingerprint'],
                        'show'        => false,
                        'publicKey'   => $c['pubkey'],
                        'shortId'     => $c['shortid'],
                        'spiderX'     => '',
                    ],
                ],
            ],
            ['tag' => 'direct', 'protocol' => 'freedom'],
        ],
        'routing' => [
            'domainStrategy' => 'IPIfNonMatch',
            'rules' => [[
                'type'        => 'field',
                'ip'          => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
                'outboundTag' => 'direct',
            ]],
        ],
    ];

    if (!is_dir(XRAY_CONF_DIR)) {
        mkdir(XRAY_CONF_DIR, 0750, true);
    }
    file_put_contents(
        XRAY_CONF,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    chmod(XRAY_CONF, 0640);
}

// ─── Write tun2socks config.yaml ─────────────────────────────────────────────
function t2s_write_config(array $c): void
{
    if (!is_dir(T2S_CONF_DIR)) {
        mkdir(T2S_CONF_DIR, 0750, true);
    }
    $yaml = "proxy: socks5://127.0.0.1:{$c['socks5_port']}\n"
          . "device: {$c['tun_iface']}\n"
          . "mtu: {$c['mtu']}\n"
          . "loglevel: info\n";
    file_put_contents(T2S_CONF, $yaml);
    chmod(T2S_CONF, 0640);
}

// ─── PID helpers (FreeBSD: no posix extension — use /bin/kill) ───────────────
function proc_is_running(string $pidfile): bool
{
    if (!file_exists($pidfile)) {
        return false;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return false;
    }
    // /bin/kill -0 проверяет существование процесса без его завершения
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

function proc_kill(string $pidfile): void
{
    if (!file_exists($pidfile)) {
        return;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid > 0) {
        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        // ждём завершения до 3 секунд
        $i = 0;
        while ($i++ < 30) {
            exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                break;
            }
            usleep(100000);
        }
        // если не завершился — KILL
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out2, $rc2);
        if ($rc2 === 0) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
    @unlink($pidfile);
}

function proc_start(string $bin, string $args, string $pidfile): void
{
    exec('/usr/sbin/daemon -p ' . escapeshellarg($pidfile)
       . ' ' . escapeshellarg($bin) . ' ' . $args . ' > /dev/null 2>&1 &');
}

// ─── High-level actions ───────────────────────────────────────────────────────
function do_stop(): void
{
    proc_kill(T2S_PID);
    proc_kill(XRAY_PID);
    echo "Stopped.\n";
}

function do_start(array $c): void
{
    if (!file_exists(XRAY_BIN)) {
        echo "ERROR: xray-core not found at " . XRAY_BIN . "\n";
        exit(1);
    }
    if (!file_exists(T2S_BIN)) {
        echo "ERROR: tun2socks not found at " . T2S_BIN . "\n";
        exit(1);
    }

    xray_write_config($c);
    t2s_write_config($c);

    if (!proc_is_running(XRAY_PID)) {
        proc_start(XRAY_BIN, 'run -c ' . escapeshellarg(XRAY_CONF), XRAY_PID);
        usleep(800000);
    }
    if (!proc_is_running(T2S_PID)) {
        proc_start(T2S_BIN, '-config ' . escapeshellarg(T2S_CONF), T2S_PID);
        usleep(800000);
    }

    echo "Started.\n";
}

function do_status(): void
{
    $xray = proc_is_running(XRAY_PID);
    $t2s  = proc_is_running(T2S_PID);
    echo json_encode([
        'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
        'xray_core' => $xray ? 'running' : 'stopped',
        'tun2socks' => $t2s  ? 'running' : 'stopped',
    ]) . "\n";
}

// ─── Main ────────────────────────────────────────────────────────────────────
$action = $argv[1] ?? 'status';

switch ($action) {
    case 'start':
        $c = xray_get_config();
        if (empty($c) || !$c['enabled']) {
            echo "Xray is disabled in config.\n";
            exit(0);
        }
        do_start($c);
        break;

    case 'stop':
        do_stop();
        break;

    case 'restart':
        do_stop();
        sleep(1);
        $c = xray_get_config();
        if (!empty($c) && $c['enabled']) {
            do_start($c);
        }
        break;

    case 'reconfigure':
        do_stop();
        sleep(1);
        $c = xray_get_config();
        if (!empty($c) && $c['enabled']) {
            do_start($c);
            echo "OK\n";
        } else {
            echo "Xray disabled — services stopped.\n";
        }
        break;

    case 'status':
        do_status();
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}
