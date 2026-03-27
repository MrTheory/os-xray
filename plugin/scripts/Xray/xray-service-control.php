#!/usr/local/bin/php
<?php

require_once('config.inc');

// ─── Shared constants (not per-instance) ─────────────────────────────────────
define('XRAY_BIN',          '/usr/local/bin/xray-core');
define('XRAY_CONF_DIR',     '/usr/local/etc/xray-core');
define('T2S_BIN',           '/usr/local/tun2socks/tun2socks');
define('T2S_CONF_DIR',      '/usr/local/tun2socks');
// BUG-7 FIX: stderr демонов в лог-файл вместо /dev/null
define('XRAY_DAEMON_LOG',   '/var/log/xray-core.log');
define('XRAY_VERSION_FILE', '/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt');

// ─── Per-instance path functions ─────────────────────────────────────────────
// v3.0.0: все runtime-файлы именуются по UUID инстанса, чтобы N инстансов
// не конфликтовали за один PID-файл / конфиг / lock.
function xray_conf_path(string $inst_uuid): string
{
    return XRAY_CONF_DIR . "/config-{$inst_uuid}.json";
}
function xray_pid_path(string $inst_uuid): string
{
    return "/var/run/xray_core_{$inst_uuid}.pid";
}
function t2s_conf_path(string $inst_uuid): string
{
    return T2S_CONF_DIR . "/config-{$inst_uuid}.yaml";
}
function t2s_pid_path(string $inst_uuid): string
{
    return "/var/run/tun2socks_{$inst_uuid}.pid";
}
function xray_lock_path(string $inst_uuid): string
{
    return "/var/run/xray_start_{$inst_uuid}.lock";
}
function xray_stopped_flag(string $inst_uuid): string
{
    return "/var/run/xray_stopped_{$inst_uuid}.flag";
}

// ─── Read config from OPNsense config.xml ────────────────────────────────────

/**
 * Парсит один <instance> SimpleXMLElement в плоский PHP-массив конфига.
 * globalEnabled — значение general.enabled (общий выключатель плагина).
 */
function xray_parse_instance($inst, bool $globalEnabled): array
{
    // B6: нормализация loglevel.
    // Старые: ключ "e" (до v1.0.1) → "error"
    // Новые:  ключ "loglevel_error" (v1.0.1+) → "error"
    $rawLevel = (string)($inst->loglevel ?? 'warning');
    $levelMap = [
        'e'              => 'error',
        'loglevel_error' => 'error',
    ];
    $loglevel = $levelMap[$rawLevel] ?? ($rawLevel ?: 'warning');

    return [
        'enabled'         => $globalEnabled,
        'name'            => (string)($inst->name             ?? 'default'),
        'server'          => (string)($inst->server_address   ?? ''),
        'port'            => (int)(string)($inst->server_port ?? 443),
        'vless_uuid'      => (string)($inst->vless_uuid        ?? ''),
        'flow'            => (string)($inst->flow             ?? 'xtls-rprx-vision'),
        'sni'             => (string)($inst->reality_sni      ?? ''),
        'pubkey'          => (string)($inst->reality_pubkey   ?? ''),
        'shortid'         => (string)($inst->reality_shortid  ?? ''),
        'fingerprint'     => (string)($inst->reality_fingerprint ?? 'chrome'),
        'socks5_listen'   => (string)($inst->socks5_listen    ?? '127.0.0.1') ?: '127.0.0.1',
        'socks5_port'     => (int)(string)($inst->socks5_port ?? 10808) ?: 10808,
        'tun_iface'       => (string)($inst->tun_interface    ?? 'proxytun2socks0'),
        'mtu'             => (int)(string)($inst->mtu         ?? 1500),
        'loglevel'        => $loglevel,
        'bypass_networks' => (string)($inst->bypass_networks  ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')
                            ?: '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
        'config_mode'     => (string)($inst->config_mode      ?? 'wizard') ?: 'wizard',
        'custom_config'   => (string)($inst->custom_config    ?? ''),
    ];
}

/**
 * Читает все инстансы из config.xml.
 * Возвращает массив, индексированный по inst_uuid (UUID инстанса OPNsense).
 */
function xray_get_all_instances(): array
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $g   = $cfg->OPNsense->xray->general   ?? null;
    $ins = $cfg->OPNsense->xray->instances  ?? null;

    $globalEnabled = (string)($g->enabled ?? '0') === '1';

    if (!$ins) {
        return [];
    }

    $result = [];
    foreach ($ins->instance as $inst) {
        // SimpleXML: атрибуты читаются через $element['attr']
        $inst_uuid = (string)$inst['uuid'];
        if ($inst_uuid === '') {
            continue;
        }
        $c = xray_parse_instance($inst, $globalEnabled);
        $c['inst_uuid'] = $inst_uuid;
        $result[$inst_uuid] = $c;
    }
    return $result;
}

/**
 * Читает конфиг конкретного инстанса по его UUID.
 * Если UUID не указан — возвращает первый найденный инстанс (обратная совместимость).
 */
function xray_get_config(string $inst_uuid = ''): array
{
    $all = xray_get_all_instances();
    if (empty($all)) {
        return [];
    }
    if ($inst_uuid !== '' && isset($all[$inst_uuid])) {
        return $all[$inst_uuid];
    }
    // Возвращаем первый инстанс если UUID не указан
    return reset($all);
}

// ─── Build xray config array ──────────────────────────────────────────────────
function xray_build_config_array(array $c): array
{
    $flow = ($c['flow'] === 'none' || $c['flow'] === '') ? '' : $c['flow'];

    // P2-5/6: парсим bypass_networks из comma-separated строки
    $bypassRaw  = $c['bypass_networks'] ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
    $bypassNets = array_values(array_filter(array_map('trim', explode(',', $bypassRaw))));
    if (empty($bypassNets)) {
        $bypassNets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    }

    return [
        'log'      => ['loglevel' => $c['loglevel'] ?: 'warning'],
        'inbounds' => [[
            'tag'      => 'socks-in',
            'port'     => $c['socks5_port'],
            'listen'   => $c['socks5_listen'],
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => $c['socks5_listen']],
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
                            'id'         => $c['vless_uuid'],
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
                'ip'          => $bypassNets,
                'outboundTag' => 'direct',
            ]],
        ],
    ];
}

// ─── P2.5: xhttp/splithttp compatibility ─────────────────────────────────────
function xray_normalize_transport(string $json): string
{
    if (!file_exists(XRAY_BIN)) {
        return $json;
    }
    exec(escapeshellarg(XRAY_BIN) . ' version 2>/dev/null', $out);
    $verLine = $out[0] ?? '';

    if (preg_match('/Xray\s+1\./', $verLine)) {
        $json = str_replace('"xhttp"', '"splithttp"', $json);
        $json = str_replace('"xhttpSettings"', '"splithttpSettings"', $json);
    }

    return $json;
}

// ─── Write xray config.json (per-instance) ───────────────────────────────────
function xray_write_config(array $c): void
{
    if (!is_dir(XRAY_CONF_DIR)) {
        mkdir(XRAY_CONF_DIR, 0750, true);
    }

    $inst_uuid = $c['inst_uuid'];
    $confFile  = xray_conf_path($inst_uuid);

    if (($c['config_mode'] ?? 'wizard') === 'custom') {
        $raw = trim($c['custom_config'] ?? '');
        if ($raw === '') {
            echo "ERROR: custom_config is empty\n";
            return;
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            echo "ERROR: custom_config is not valid JSON\n";
            return;
        }
        $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = xray_normalize_transport($json);
    } else {
        $cfg  = xray_build_config_array($c);
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    file_put_contents($confFile, $json);
    chmod($confFile, 0640);
}

// ─── Write tun2socks config.yaml (per-instance) ──────────────────────────────
function t2s_write_config(array $c): void
{
    if (!is_dir(T2S_CONF_DIR)) {
        mkdir(T2S_CONF_DIR, 0750, true);
    }
    $inst_uuid = $c['inst_uuid'];
    $yaml = "proxy: socks5://{$c['socks5_listen']}:{$c['socks5_port']}\n"
          . "device: {$c['tun_iface']}\n"
          . "mtu: {$c['mtu']}\n"
          . "loglevel: info\n";
    file_put_contents(t2s_conf_path($inst_uuid), $yaml);
    chmod(t2s_conf_path($inst_uuid), 0640);
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
        // БАГ-4 FIX: проверяем что PID принадлежит нашему процессу
        $comm = trim((string)shell_exec('ps -o comm= -p ' . $pid . ' 2>/dev/null'));
        if ($comm === '' || (strpos($comm, 'xray') === false && strpos($comm, 'tun2socks') === false)) {
            @unlink($pidfile);
            return;
        }

        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        $i = 0;
        while ($i++ < 30) {
            exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                break;
            }
            usleep(100000);
        }
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out2, $rc2);
        if ($rc2 === 0) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
    @unlink($pidfile);
}

function proc_start(string $bin, string $args, string $pidfile): void
{
    // BUG-7 FIX: stderr демона → XRAY_DAEMON_LOG вместо /dev/null
    $log = escapeshellarg(XRAY_DAEMON_LOG);
    exec('/usr/sbin/daemon -p ' . escapeshellarg($pidfile)
       . ' ' . escapeshellarg($bin) . ' ' . $args . ' >> ' . $log . ' 2>&1 &');
}

// ─── Per-instance lock helpers ────────────────────────────────────────────────
/**
 * Захватывает эксклюзивный non-blocking lock для конкретного инстанса.
 * Возвращает дескриптор при успехе, false если lock уже захвачен.
 * Вызывающий ОБЯЗАН вызвать lock_release($fd, $inst_uuid) после завершения.
 *
 * @return resource|false
 */
function lock_acquire(string $inst_uuid)
{
    $lockPath = xray_lock_path($inst_uuid);
    $fd = fopen($lockPath, 'c');
    if ($fd === false) {
        return false;
    }
    if (!flock($fd, LOCK_EX | LOCK_NB)) {
        fclose($fd);
        return false;
    }
    fwrite($fd, (string)getmypid());
    fflush($fd);
    return $fd;
}

/**
 * Освобождает lock инстанса, закрывает дескриптор, удаляет lock-файл.
 *
 * @param resource $fd
 */
function lock_release($fd, string $inst_uuid): void
{
    flock($fd, LOCK_UN);
    fclose($fd);
    @unlink(xray_lock_path($inst_uuid));
}

// ─── BUG-3 FIX: config validation before start ──────────────────────────────
function xray_validate_config(string $confFile): bool
{
    if (!file_exists(XRAY_BIN)) {
        return true;
    }
    if (!file_exists($confFile)) {
        echo "ERROR: config file not found after write: {$confFile}\n";
        return false;
    }
    exec(escapeshellarg(XRAY_BIN) . ' -test -c ' . escapeshellarg($confFile) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        echo "ERROR: xray config validation failed:\n" . implode("\n", $out) . "\n";
        return false;
    }
    return true;
}

// ─── lo0 alias management ─────────────────────────────────────────────────────
function lo0_needs_alias(string $addr): bool
{
    if ($addr === '127.0.0.1' || $addr === '0.0.0.0') {
        return false;
    }
    $parts = explode('.', $addr);
    return count($parts) === 4 && $parts[0] === '127';
}

function lo0_alias_ensure(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        echo "WARNING: Cannot read lo0 interface\n";
        return;
    }
    $ifOutput = implode("\n", $out);
    if (strpos($ifOutput, $addr) !== false) {
        return;
    }
    exec('/sbin/ifconfig lo0 alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out2, $rc2);
    if ($rc2 !== 0) {
        echo "WARNING: Failed to add lo0 alias {$addr}\n";
    } else {
        echo "INFO: Added lo0 alias {$addr}\n";
    }
}

function lo0_alias_remove(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 -alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out, $rc);
    if ($rc === 0) {
        echo "INFO: Removed lo0 alias {$addr}\n";
    }
}

// ─── B9: TUN interface teardown ───────────────────────────────────────────────
function tun_destroy(string $iface): void
{
    if (empty($iface)) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' destroy 2>/dev/null');
}

// ─── High-level per-instance actions ─────────────────────────────────────────

/**
 * do_stop() — останавливает tun2socks и xray-core инстанса, выставляет stopped flag.
 */
function do_stop(string $inst_uuid, ?string $tunIface = null): void
{
    if ($tunIface === null) {
        $c        = xray_get_config($inst_uuid);
        $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
    }

    // Останавливаем tun2socks первым — он держит TUN open.
    proc_kill(t2s_pid_path($inst_uuid));
    // Останавливаем xray-core
    proc_kill(xray_pid_path($inst_uuid));

    // Удаляем lo0 alias если был добавлен
    $c2 = xray_get_config($inst_uuid);
    lo0_alias_remove($c2['socks5_listen'] ?? '127.0.0.1');

    // БАГ-5 FIX: флаг намеренной остановки — watchdog не перезапускает
    file_put_contents(xray_stopped_flag($inst_uuid), date('Y-m-d H:i:s'));

    echo "Stopped.\n";
}

/**
 * do_start() — генерирует конфиги, запускает xray-core и tun2socks инстанса.
 */
function do_start(array $c): bool
{
    if (!file_exists(XRAY_BIN)) {
        echo "ERROR: xray-core not found at " . XRAY_BIN . "\n";
        return false;
    }
    if (!file_exists(T2S_BIN)) {
        echo "ERROR: tun2socks not found at " . T2S_BIN . "\n";
        return false;
    }

    $inst_uuid = $c['inst_uuid'];

    // B7: захватываем per-instance lock перед запуском
    $lock = lock_acquire($inst_uuid);
    if ($lock === false) {
        echo "INFO: Another start is already in progress for instance {$inst_uuid} (lock held). Skipping.\n";
        return true;
    }

    try {
        // БАГ-5 FIX: снимаем флаг намеренной остановки
        @unlink(xray_stopped_flag($inst_uuid));

        xray_write_config($c);
        t2s_write_config($c);

        lo0_alias_ensure($c['socks5_listen']);

        // BUG-3 FIX: валидация конфига до запуска
        if (!xray_validate_config(xray_conf_path($inst_uuid))) {
            return false;
        }

        if (!proc_is_running(xray_pid_path($inst_uuid))) {
            proc_start(XRAY_BIN, 'run -c ' . escapeshellarg(xray_conf_path($inst_uuid)), xray_pid_path($inst_uuid));
            usleep(800000);
        }
        if (!proc_is_running(t2s_pid_path($inst_uuid))) {
            proc_start(T2S_BIN, '-config ' . escapeshellarg(t2s_conf_path($inst_uuid)), t2s_pid_path($inst_uuid));
            usleep(800000);
        }

        // Назначаем IP на TUN через syshook (ждёт TUN, читает IP из config, reload firewall)
        exec('/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray ' . escapeshellarg($inst_uuid) . ' &');

        echo "Started.\n";
        return true;
    } finally {
        // B7: освобождаем lock гарантированно (даже при исключении)
        lock_release($lock, $inst_uuid);
    }
}

function do_status(string $inst_uuid = ''): void
{
    if ($inst_uuid !== '') {
        $xray = proc_is_running(xray_pid_path($inst_uuid));
        $t2s  = proc_is_running(t2s_pid_path($inst_uuid));
        echo json_encode([
            'status'      => ($xray && $t2s) ? 'ok' : 'stopped',
            'xray_core'   => $xray ? 'running' : 'stopped',
            'tun2socks'   => $t2s  ? 'running' : 'stopped',
            'inst_uuid'   => $inst_uuid,
        ]) . "\n";
        return;
    }

    // Без UUID: статус всех инстансов + агрегированный статус
    $all = xray_get_all_instances();
    if (empty($all)) {
        echo json_encode(['status' => 'stopped', 'xray_core' => 'stopped', 'tun2socks' => 'stopped']) . "\n";
        return;
    }
    // Для совместимости с текущим GUI: возвращаем статус первого инстанса
    $first = reset($all);
    $uuid0 = $first['inst_uuid'];
    $xray  = proc_is_running(xray_pid_path($uuid0));
    $t2s   = proc_is_running(t2s_pid_path($uuid0));
    echo json_encode([
        'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
        'xray_core' => $xray ? 'running' : 'stopped',
        'tun2socks' => $t2s  ? 'running' : 'stopped',
    ]) . "\n";
}

function do_status_all(): void
{
    $all    = xray_get_all_instances();
    $result = [];
    foreach ($all as $inst_uuid => $c) {
        $xray = proc_is_running(xray_pid_path($inst_uuid));
        $t2s  = proc_is_running(t2s_pid_path($inst_uuid));
        $result[$inst_uuid] = [
            'name'      => $c['name'],
            'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
            'xray_core' => $xray ? 'running' : 'stopped',
            'tun2socks' => $t2s  ? 'running' : 'stopped',
        ];
    }
    echo json_encode($result) . "\n";
}

// ─── Main ─────────────────────────────────────────────────────────────────────
$action    = $argv[1] ?? 'status';
$inst_uuid = isset($argv[2]) ? trim($argv[2]) : '';

// Базовая санитизация UUID аргумента
// configd передаёт литерал "%1" когда аргумент не указан — отбрасываем
if ($inst_uuid !== '') {
    $inst_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $inst_uuid);
    // UUID должен быть минимум 32 hex-символа + 4 дефиса = 36 символов
    if (strlen($inst_uuid) < 36) {
        $inst_uuid = '';
    }
}

switch ($action) {
    case 'start':
        if ($inst_uuid !== '') {
            $c = xray_get_config($inst_uuid);
            if (empty($c) || !$c['enabled']) {
                echo "Xray is disabled or instance not found.\n";
                exit(0);
            }
            $ok = do_start($c);
            exit($ok ? 0 : 1);
        }
        // Запускаем все включённые инстансы
        $all = xray_get_all_instances();
        if (empty($all)) {
            echo "No instances configured.\n";
            exit(0);
        }
        $anyFailed = false;
        foreach ($all as $uuid => $c) {
            if (!$c['enabled']) continue;
            if (!do_start($c)) {
                $anyFailed = true;
            }
        }
        exit($anyFailed ? 1 : 0);

    case 'stop':
        if ($inst_uuid !== '') {
            do_stop($inst_uuid);
        } else {
            foreach (array_keys(xray_get_all_instances()) as $uuid) {
                do_stop($uuid);
            }
        }
        break;

    case 'restart':
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                do_start($c);
            }
        } else {
            $all = xray_get_all_instances();
            foreach ($all as $uuid => $c) {
                $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
                do_stop($uuid, $tunIface);
            }
            sleep(1);
            foreach ($all as $uuid => $c) {
                if ($c['enabled']) do_start($c);
            }
        }
        break;

    case 'reconfigure':
        // B10: возвращаем реальный статус
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                $ok = do_start($c);
                if ($ok) {
                    echo "OK\n";
                    exit(0);
                } else {
                    echo "ERROR: Failed to start Xray services for instance {$inst_uuid}.\n";
                    exit(1);
                }
            } else {
                echo "Xray disabled — services stopped.\n";
                exit(0);
            }
        }
        // Без UUID: рекофигурируем все инстансы
        $all       = xray_get_all_instances();
        $allStopped = [];
        foreach ($all as $uuid => $c) {
            $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
            do_stop($uuid, $tunIface);
            $allStopped[$uuid] = $c;
        }
        sleep(1);
        $anyFailed = false;
        foreach ($allStopped as $uuid => $c) {
            if ($c['enabled']) {
                if (!do_start($c)) {
                    $anyFailed = true;
                }
            }
        }
        if ($anyFailed) {
            echo "ERROR: One or more instances failed to start.\n";
            exit(1);
        }
        echo "OK\n";
        exit(0);

    case 'status':
        do_status($inst_uuid);
        break;

    case 'statusall':
        do_status_all();
        break;

    case 'validate':
        // БАГ-6 FIX: сухой прогон через временный файл (рабочий конфиг не перезаписывается)
        $c = $inst_uuid !== '' ? xray_get_config($inst_uuid) : xray_get_config();
        if (empty($c)) {
            echo "ERROR: No xray config found in OPNsense config.xml\n";
            exit(1);
        }
        $tmpBase = tempnam('/tmp', 'xray-validate-');
        if ($tmpBase === false) {
            echo "ERROR: Cannot create temp file for validation\n";
            exit(1);
        }
        $tmpConf = $tmpBase . '.json';
        @unlink($tmpBase);
        try {
            if (($c['config_mode'] ?? 'wizard') === 'custom') {
                $raw = trim($c['custom_config'] ?? '');
                if ($raw === '') {
                    echo "ERROR: custom_config is empty\n";
                    exit(1);
                }
                $decoded = json_decode($raw, true);
                if ($decoded === null) {
                    echo "ERROR: custom_config is not valid JSON\n";
                    exit(1);
                }
                $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $json = xray_normalize_transport($json);
            } else {
                $json = json_encode(
                    xray_build_config_array($c),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            }
            file_put_contents($tmpConf, $json);
            chmod($tmpConf, 0600);
            if (xray_validate_config($tmpConf)) {
                echo "OK: config is valid\n";
                exit(0);
            } else {
                exit(1);
            }
        } finally {
            @unlink($tmpConf);
        }

    case 'version':
        $ver = file_exists(XRAY_VERSION_FILE) ? trim(file_get_contents(XRAY_VERSION_FILE)) : 'unknown';
        echo json_encode(['version' => $ver]) . "\n";
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}
