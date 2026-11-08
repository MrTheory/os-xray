#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once(__DIR__ . '/xray-boot-log.inc.php');
require_once(__DIR__ . '/xray-bird-peers.php');
require_once(__DIR__ . '/xray-bird-active-tun.php');
require_once(__DIR__ . '/xray-dnstap-unbound.php');
require_once(__DIR__ . '/xray-static-conf.php');
require_once(__DIR__ . '/xray-gateways.php');

// ─── Shared constants (not per-instance) ─────────────────────────────────────
define('XRAY_BIN',          '/usr/local/bin/xray-core');
define('XRAY_CONF_DIR',     '/usr/local/etc/xray-core');
define('T2S_BIN',           '/usr/local/tun2socks/tun2socks');
define('T2S_CONF_DIR',      '/usr/local/tun2socks');
// BUG-7 FIX: stderr демонов в лог-файл вместо /dev/null
define('XRAY_DAEMON_LOG',   '/var/log/xray-core.log'); // общий лог (fallback)
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
function xray_instance_log(string $inst_uuid): string
{
    return "/var/log/xray-core-{$inst_uuid}.log";
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
        'enabled'         => $globalEnabled && (string)($inst->enabled ?? '1') === '1',
        'name'            => (string)($inst->name             ?? ''),
        'outbound_config' => (string)($inst->outbound_config  ?? ''),
        'socks5_listen'   => (string)($inst->socks5_listen    ?? '127.0.0.1') ?: '127.0.0.1',
        'socks5_port'     => (int)(string)($inst->socks5_port ?? 10808) ?: 10808,
        'tun_iface'       => (string)($inst->tun_interface    ?? 'proxytun2socks0'),
        'mtu'             => (int)(string)($inst->mtu         ?? 1500),
        'use_ipv4'        => (string)($inst->use_ipv4         ?? '1'),
        'use_ipv6'        => (string)($inst->use_ipv6         ?? '0'),
        'dns_servers'     => (string)($inst->dns_servers      ?? '1.1.1.1,8.8.8.8') ?: '1.1.1.1,8.8.8.8',
        'loglevel'        => $loglevel,
        'bypass_networks' => (string)($inst->bypass_networks  ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')
                            ?: '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
    ];
}

function xray_ip_version_flags(array $c): array
{
    return [
        'use_ipv4' => (string)($c['use_ipv4'] ?? '1') === '1',
        'use_ipv6' => (string)($c['use_ipv6'] ?? '0') === '1',
    ];
}

function xray_ip_query_strategy(bool $useIpv4, bool $useIpv6): string
{
    if ($useIpv4 && $useIpv6) {
        return 'UseIP';
    }
    if ($useIpv6) {
        return 'UseIPv6';
    }
    return 'UseIPv4';
}

/**
 * routing.domainStrategy: AsIs / IPIfNonMatch / IPOnDemand.
 * Dual-stack → IPIfNonMatch; только IPv4 или только IPv6 → IPOnDemand.
 * Выбор семейства адресов — через dns.queryStrategy и правила block.
 */
function xray_routing_domain_strategy(bool $useIpv4, bool $useIpv6): string
{
    if ($useIpv4 && $useIpv6) {
        return 'IPIfNonMatch';
    }
    return 'IPOnDemand';
}

function xray_validate_ip_stack(array $c, string $inst_uuid = ''): bool
{
    $flags = xray_ip_version_flags($c);
    if ($flags['use_ipv4'] || $flags['use_ipv6']) {
        return true;
    }
    $suffix = $inst_uuid !== '' ? " for instance {$inst_uuid}" : '';
    echo "ERROR: At least one of IPv4 or IPv6 must be enabled{$suffix}.\n";
    return false;
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

// ─── Build routing block from comma-separated CIDR string ────────────────────
/**
 * domainStrategy на уровне outbound (proxy): только IPv4 → UseIPv4, только IPv6 → UseIPv6.
 * Dual-stack — поле не трогаем (оставляем как в outbound_config / дефолт xray).
 */
function xray_outbound_domain_strategy(bool $useIpv4, bool $useIpv6): ?string
{
    if ($useIpv4 && !$useIpv6) {
        return 'UseIPv4';
    }
    if ($useIpv6 && !$useIpv4) {
        return 'UseIPv6';
    }
    return null;
}

function xray_build_outbounds(array $proxyOutbound, array $c): array
{
    $flags = xray_ip_version_flags($c);
    $ds    = xray_outbound_domain_strategy($flags['use_ipv4'], $flags['use_ipv6']);
    if ($ds !== null) {
        $proxyOutbound['domainStrategy'] = $ds;
    }

    $outbounds = [
        $proxyOutbound,
        ['tag' => 'direct', 'protocol' => 'freedom'],
    ];
    if (!$flags['use_ipv4'] || !$flags['use_ipv6']) {
        $outbounds[] = ['tag' => 'block', 'protocol' => 'blackhole'];
    }
    return $outbounds;
}

function xray_build_routing(string $bypassRaw, array $c): array
{
    $bypassNets = array_values(array_filter(array_map('trim', explode(',', $bypassRaw))));
    if (empty($bypassNets)) {
        $bypassNets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    }
    $flags = xray_ip_version_flags($c);
    $rules = [];

    // Блокировка IPv6 в routing, если чекбокс IPv6 выключен (TUN IPv6 при этом может быть назначен)
    if (!$flags['use_ipv6']) {
        $rules[] = [
            'type'        => 'field',
            'ip'          => ['::/0'],
            'outboundTag' => 'block',
        ];
    }
    if (!$flags['use_ipv4']) {
        $rules[] = [
            'type'        => 'field',
            'ip'          => ['0.0.0.0/0'],
            'outboundTag' => 'block',
        ];
    }

    $rules[] = [
        'type'        => 'field',
        'ip'          => $bypassNets,
        'outboundTag' => 'direct',
    ];

    return [
        'domainStrategy' => xray_routing_domain_strategy($flags['use_ipv4'], $flags['use_ipv6']),
        'rules'          => $rules,
    ];
}

// ─── DNS block: per-instance servers + IP stack query strategy ───────────────
function xray_build_dns(array $c): array
{
    $raw = trim($c['dns_servers'] ?? '');
    $servers = array_values(array_filter(array_map('trim', explode(',', $raw))));
    if (empty($servers)) {
        $servers = ['1.1.1.1', '8.8.8.8'];
    }
    $flags = xray_ip_version_flags($c);
    return [
        'servers'       => $servers,
        'queryStrategy' => xray_ip_query_strategy($flags['use_ipv4'], $flags['use_ipv6']),
    ];
}

/**
 * Собирает полный config.json для xray-core из параметров инстанса.
 *
 * @return array|null null при ошибке outbound_config
 */
function xray_build_config_array(array $c): ?array
{
    $raw = trim($c['outbound_config'] ?? '');
    if ($raw === '') {
        echo "ERROR: outbound_config is empty\n";
        return null;
    }
    $outbound = json_decode($raw, true);
    if ($outbound === null) {
        echo "ERROR: outbound_config is not valid JSON\n";
        return null;
    }

    $flags = xray_ip_version_flags($c);
    // Sniffing только для single-stack; dual-stack (IPv4+IPv6) — выключен
    $sniffing = ($flags['use_ipv4'] && $flags['use_ipv6'])
        ? ['enabled' => false]
        : [
            'enabled'      => true,
            'destOverride' => ['http', 'tls', 'quic'],
            'metadataOnly' => false,
        ];

    return [
        'log'       => ['loglevel' => $c['loglevel'] ?? 'warning'],
        'dns'       => xray_build_dns($c),
        'inbounds'  => [[
            'tag'      => 'socks-in',
            'port'     => (int)($c['socks5_port'] ?? 10808),
            'listen'   => $c['socks5_listen'] ?? '127.0.0.1',
            'protocol' => 'socks',
            'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => $c['socks5_listen'] ?? '127.0.0.1'],
            'sniffing' => $sniffing,
        ]],
        'outbounds' => xray_build_outbounds($outbound, $c),
        'routing'   => xray_build_routing($c['bypass_networks'] ?? '', $c),
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
function xray_write_config(array $c): bool
{
    if (!is_dir(XRAY_CONF_DIR)) {
        if (!mkdir(XRAY_CONF_DIR, 0750, true) && !is_dir(XRAY_CONF_DIR)) {
            echo "ERROR: Cannot create config directory " . XRAY_CONF_DIR . "\n";
            return false;
        }
    }

    $inst_uuid = $c['inst_uuid'] ?? '';
    if ($inst_uuid === '') {
        echo "ERROR: inst_uuid is empty, cannot write config\n";
        return false;
    }
    $confFile  = xray_conf_path($inst_uuid);

    $config = xray_build_config_array($c);
    if ($config === null) {
        return false;
    }
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo "ERROR: Failed to encode config JSON\n";
        return false;
    }
    $json = xray_normalize_transport($json);

    if (file_put_contents($confFile, $json) === false) {
        echo "ERROR: Failed to write config file {$confFile}\n";
        return false;
    }
    chmod($confFile, 0640);
    echo "INFO: Wrote xray config {$confFile}\n";
    return true;
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

function proc_start(string $bin, string $args, string $pidfile, string $logfile = ''): void
{
    // BUG-7 FIX: stderr демона → XRAY_DAEMON_LOG вместо /dev/null
    $log = escapeshellarg($logfile !== '' ? $logfile : XRAY_DAEMON_LOG);
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
function tun_iface_exists(string $iface): bool
{
    if ($iface === '') {
        return false;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

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

/**
 * Перед стартом tun2socks: удалить stale PID и TUN, если процесс не запущен.
 * Иначе tun2socks падает с "interface already exists" (crash / leftover iface).
 * На stop destroy НЕ вызываем — TUN убирает сам tun2socks (как upstream).
 */
function t2s_prepare_start(string $iface, string $inst_uuid): void
{
    $pidPath = t2s_pid_path($inst_uuid);
    if (proc_is_running($pidPath)) {
        return;
    }
    @unlink($pidPath);
    if (tun_iface_exists($iface)) {
        echo "INFO: Removing stale TUN {$iface} before tun2socks start\n";
        tun_destroy($iface);
        usleep(300000);
    }
}

// ─── High-level per-instance actions ─────────────────────────────────────────

/**
 * do_stop() — останавливает tun2socks и xray-core инстанса, выставляет stopped flag.
 * TUN не destroy'им: tun2socks сам уничтожает интерфейс при SIGTERM (upstream 1.9.2).
 * Stale iface убирается только в t2s_prepare_start() при следующем старте.
 */
function do_stop(string $inst_uuid, ?string $tunIface = null): void
{
    if ($tunIface === null) {
        $c        = xray_get_config($inst_uuid);
        $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
    }

    // Останавливаем tun2socks первым — он держит TUN open и сам его destroy'ит.
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

        if (!xray_validate_ip_stack($c, $inst_uuid)) {
            return false;
        }

        if (!xray_write_config($c)) {
            return false;
        }
        t2s_write_config($c);

        lo0_alias_ensure($c['socks5_listen']);

        // BUG-3 FIX: валидация конфига до запуска
        if (!xray_validate_config(xray_conf_path($inst_uuid))) {
            return false;
        }

        $instLog = xray_instance_log($inst_uuid);
        if (!proc_is_running(xray_pid_path($inst_uuid))) {
            proc_start(XRAY_BIN, 'run -c ' . escapeshellarg(xray_conf_path($inst_uuid)), xray_pid_path($inst_uuid), $instLog);
            usleep(800000);
        }
        if (!proc_is_running(t2s_pid_path($inst_uuid))) {
            t2s_prepare_start($c['tun_iface'] ?? 'proxytun2socks0', $inst_uuid);
            // tun2socks v2 (pflag): --config, not -config (-c + "onfig" → open onfig)
            proc_start(T2S_BIN, '--config ' . escapeshellarg(t2s_conf_path($inst_uuid)), t2s_pid_path($inst_uuid), $instLog);
            usleep(800000);
            if (!proc_is_running(t2s_pid_path($inst_uuid))) {
                echo "ERROR: tun2socks failed to start for instance {$inst_uuid}\n";
                return false;
            }
        }

        // TUN IP via syshook. Boot/light: no filter/birdsync (deferred postboot).
        $light = function_exists('xray_boot_light_active') && xray_boot_light_active();
        $hookCmd = '/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray '
            . escapeshellarg($inst_uuid)
            . ($light ? ' light' : '')
            . ' &';
        exec($hookCmd);

        echo "Started.\n";
        return true;
    } finally {
        // B7: освобождаем lock гарантированно (даже при исключении)
        lock_release($lock, $inst_uuid);
    }
}

/**
 * do_delete() — останавливает instance, удаляет TUN и per-instance runtime-файлы.
 * Может вызываться после delBase (записи в config.xml уже нет) — тогда TUN берём из yaml.
 */
function do_delete(string $inst_uuid): bool
{
    $c        = xray_get_config($inst_uuid);
    $tunIface = 'proxytun2socks0';
    if (!empty($c['tun_iface'])) {
        $tunIface = $c['tun_iface'];
    } else {
        $yaml = @file_get_contents(t2s_conf_path($inst_uuid));
        if (is_string($yaml) && preg_match('/^device:\s*(\S+)/m', $yaml, $m)) {
            $tunIface = $m[1];
        }
    }
    $name = $c['name'] ?? $inst_uuid;

    echo "Removing instance {$name} ({$inst_uuid})...\n";

    do_stop($inst_uuid, $tunIface);
    // Даём tun2socks время убрать TUN сам; если остался — destroy (instance уходит).
    usleep(500000);
    if (tun_iface_exists($tunIface)) {
        echo "INFO: Destroying TUN {$tunIface} after instance delete\n";
        tun_destroy($tunIface);
    }

    foreach ([
        xray_conf_path($inst_uuid),
        t2s_conf_path($inst_uuid),
        xray_pid_path($inst_uuid),
        t2s_pid_path($inst_uuid),
        xray_lock_path($inst_uuid),
        xray_stopped_flag($inst_uuid),
        xray_instance_log($inst_uuid),
    ] as $path) {
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    echo "OK: instance resources removed\n";
    return true;
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

function xray_active_tun_latency_load(): array
{
    $path = '/var/run/xray_active_tun_latency.json';
    if (!is_readable($path)) {
        return [];
    }
    $j = json_decode((string)file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function xray_if_test_results_load(): array
{
    $inc = __DIR__ . '/xray-if-test.inc.php';
    if (is_readable($inc)) {
        require_once($inc);
        if (function_exists('xray_if_test_load')) {
            return xray_if_test_load();
        }
    }
    return ['instances' => []];
}

function do_status_all(): void
{
    $all    = xray_get_all_instances();
    $lat    = xray_active_tun_latency_load();
    $act4   = (string)($lat['active_v4'] ?? '');
    $act6   = (string)($lat['active_v6'] ?? '');
    $byUuid = is_array($lat['instances'] ?? null) ? $lat['instances'] : [];
    $ifStore = xray_if_test_results_load();
    $ifByUuid = is_array($ifStore['instances'] ?? null) ? $ifStore['instances'] : [];
    $result = [];
    foreach ($all as $inst_uuid => $c) {
        $xray = proc_is_running(xray_pid_path($inst_uuid));
        $t2s  = proc_is_running(t2s_pid_path($inst_uuid));
        $row  = [
            'name'      => $c['name'],
            'enabled'   => !empty($c['enabled']),
            'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
            'xray_core' => $xray ? 'running' : 'stopped',
            'tun2socks' => $t2s  ? 'running' : 'stopped',
            'tun_iface' => (string)($c['tun_iface'] ?? ''),
        ];
        $L = is_array($byUuid[$inst_uuid] ?? null) ? $byUuid[$inst_uuid] : null;
        if ($L !== null) {
            $tun = (string)($L['tun'] ?? $row['tun_iface']);
            $row['active_tun_v4'] = [
                'ok'     => !empty($L['v4_ok']),
                'time'   => !empty($L['v4_ok']) ? (float)($L['v4_time'] ?? 0) : 0.0,
                'active' => ($act4 !== '' && $tun !== '' && $tun === $act4),
            ];
            $row['active_tun_v6'] = [
                'ok'     => !empty($L['v6_ok']),
                'time'   => !empty($L['v6_ok']) ? (float)($L['v6_time'] ?? 0) : 0.0,
                'active' => ($act6 !== '' && $tun !== '' && $tun === $act6),
            ];
            // Active TUN RTT for yellow comparison (slower/faster than winner)
            $row['active_tun_v4']['active_time'] = null;
            $row['active_tun_v6']['active_time'] = null;
            foreach ($byUuid as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $ot = (string)($o['tun'] ?? '');
                if ($act4 !== '' && $ot === $act4 && !empty($o['v4_ok'])) {
                    $row['active_tun_v4']['active_time'] = (float)($o['v4_time'] ?? 0);
                }
                if ($act6 !== '' && $ot === $act6 && !empty($o['v6_ok'])) {
                    $row['active_tun_v6']['active_time'] = (float)($o['v6_time'] ?? 0);
                }
            }
        }
        $T = is_array($ifByUuid[$inst_uuid] ?? null) ? $ifByUuid[$inst_uuid] : null;
        if ($T !== null) {
            $row['test_result'] = [
                'result'    => (string)($T['result'] ?? 'failed'),
                'message'   => (string)($T['message'] ?? ''),
                'http_code' => (int)($T['http_code'] ?? 0),
                'source'    => (string)($T['source'] ?? ''),
                'tested_at' => (int)($T['tested_at'] ?? 0),
            ];
        } elseif ($L !== null) {
            // Fallback: ACTIVE TUN latency present but IF-test store empty (post-uninstall).
            $v4ok = !empty($L['v4_ok']);
            $v6ok = !empty($L['v6_ok']);
            $ok = $v4ok || $v6ok;
            $parts = [];
            if ($v4ok) {
                $parts[] = 'v4 ' . sprintf('%.3fs', (float)($L['v4_time'] ?? 0));
            }
            if ($v6ok) {
                $parts[] = 'v6 ' . sprintf('%.3fs', (float)($L['v6_time'] ?? 0));
            }
            $row['test_result'] = [
                'result'    => $ok ? 'ok' : 'failed',
                'message'   => $ok
                    ? ('OK — ' . implode(', ', $parts))
                    : 'Could not connect — SOCKS probe failed',
                'http_code' => $ok ? 200 : 0,
                'source'    => 'active_tun',
                'tested_at' => (int)($lat['updated_at'] ?? 0),
            ];
        }
        $result[$inst_uuid] = $row;
    }
    echo json_encode($result) . "\n";
}

/**
 * BGP / BIRD: bird_enable=YES when Routing checkbox is on; start bird only
 * if at least one tun2socks from this plugin is running.
 */
function xray_bgp_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return (string)($cfg->OPNsense->xray->general->bgp_enabled ?? '0') === '1';
}

function xray_any_tun2socks_running(): bool
{
    $files = glob('/var/run/tun2socks_*.pid');
    if ($files === false) {
        return false;
    }
    foreach ($files as $pidfile) {
        if (proc_is_running($pidfile)) {
            return true;
        }
    }
    return false;
}

function xray_sysrc_bird_enable(bool $enable): void
{
    xray_sysrc_set('bird_enable', $enable ? 'YES' : 'NO');
}

function xray_bird_service(string $verb): void
{
    exec('/usr/sbin/service bird ' . escapeshellarg($verb) . ' 2>&1');
}

/**
 * Выбор active TUN и старт/рестарт BIRD только при успешном HTTP через SOCKS.
 * Не используется из birdsync.
 */
function xray_bird_gated_start(string $verb): bool
{
    $sel = xray_bird_select_and_write_active_tun();
    if (empty($sel['ok'])) {
        echo "bird: no tun2socks with successful HTTP — skip {$verb}\n";
        return false;
    }
    if (!empty($sel['changed'])) {
        echo "bird: active tun changed — birdc configure\n";
        if ($verb === 'start' || $verb === 'onestart') {
            xray_bird_service($verb);
        }
        xray_bird_reload_config();
        return true;
    }
    xray_bird_service($verb);
    if (in_array($verb, ['start', 'onestart', 'restart', 'onerestart'], true)) {
        xray_bird_reload_config();
    }
    return true;
}

/** Sync BIRD static_v4/v6 protocols from general.static_enabled. */
function xray_static_apply_general(): void
{
    if (!function_exists('xray_static_write_protocols_inc')) {
        echo "static: write helper missing\n";
        return;
    }
    $on = function_exists('xray_static_enabled') && xray_static_enabled();
    if ($on && function_exists('xray_static_build_apply')) {
        // wan_if.inc (IPv4_GATEWAY) must exist before routes_static_v*_apply.inc
        if (function_exists('xray_static_ensure_wan_defines')) {
            xray_static_ensure_wan_defines();
        } elseif (function_exists('xray_bird_write_wan_if')) {
            xray_bird_write_wan_if();
        }
        // Rebuild route lines (DIRECT via IPv4_GATEWAY / IPv6 device; PROXY via ACTIVE_TUN*).
        xray_static_build_apply('ipv4');
        xray_static_build_apply('ipv6');
    }
    xray_static_write_protocols_inc($on);
    if (function_exists('xray_bird_reload_config')) {
        xray_bird_reload_config();
    } elseif (function_exists('xray_static_bird_reload')) {
        xray_static_bird_reload();
    }
    echo 'static: protocols ' . ($on ? 'enabled' : 'disabled') . "\n";
}

/** General Apply: Enable BGP → выбор TUN и старт; иначе останов. */
function xray_bird_apply_general_bgp(): void
{
    if (function_exists('xray_gateways_ensure_all')) {
        xray_gateways_ensure_all();
    }
    if (!function_exists('xray_wan_routes_sync_all')) {
        $wr = __DIR__ . '/xray-wan-static-routes.php';
        if (is_readable($wr)) {
            require_once $wr;
        }
    }
    if (function_exists('xray_wan_routes_sync_all')) {
        xray_wan_routes_sync_all(true);
    }
    xray_bird_write_peers();
    xray_static_apply_general();
    if (!xray_bgp_enabled()) {
        xray_sysrc_bird_enable(false);
        xray_bird_service('stop');
        echo "bird: BGP off, bird_enable=NO, stopped\n";
        return;
    }
    xray_sysrc_bird_enable(true);
    if (!xray_any_tun2socks_running()) {
        xray_bird_service('stop');
        echo "bird: BGP on, no tun2socks, stopped\n";
        return;
    }
    if (!xray_bird_gated_start('start')) {
        echo "bird: BGP on, no working SOCKS — not starting\n";
    }
}

function xray_bird_hold(): void
{
    if (xray_bgp_enabled()) {
        xray_bird_service('onestop');
        echo "bird hold: stopped until tun2socks is up\n";
        return;
    }
    echo "bird hold: BGP disabled, skip\n";
}

function xray_bird_sync(): void
{
    xray_bird_write_peers();
    if (function_exists('xray_static_write_protocols_inc')) {
        xray_static_write_protocols_inc();
    }
    $sel = xray_bird_select_and_write_active_tun();
    if (!empty($sel['changed'])) {
        xray_bird_active_tun_configure();
        echo "bird sync: active tun changed — birdc configure + reload in all\n";
    }
    if (!xray_bgp_enabled()) {
        xray_sysrc_bird_enable(false);
        xray_bird_service('stop');
        echo "bird sync: BGP off, bird_enable=NO, stopped\n";
        return;
    }
    xray_sysrc_bird_enable(true);
    if (xray_any_tun2socks_running()) {
        xray_bird_service('start');
        xray_bird_reload_config();
        echo "bird sync: BGP on, tun2socks up, bird started\n";
        return;
    }
    xray_bird_service('stop');
    echo "bird sync: BGP on, no tun2socks, bird stopped\n";
}

/**
 * BIRD sync does SOCKS probes and can take >60s (HTTP 504 on Start All).
 * Run it out-of-band so start/stop/restart return promptly.
 */
function xray_bird_sync_async(): void
{
    $bin = '/usr/local/sbin/configctl';
    if (!is_executable($bin)) {
        $bin = '/usr/local/bin/configctl';
    }
    if (is_executable($bin)) {
        exec(escapeshellarg($bin) . ' xray birdsync >/dev/null 2>&1 &');
        echo "bird sync: scheduled in background\n";
        return;
    }
    // Fallback: still sync inline if configctl missing
    xray_bird_sync();
}

function xray_dnstap_bgp_installed(): bool
{
    if (is_executable('/usr/local/sbin/dnstap-bgp')
        && (is_file('/usr/local/etc/rc.d/dnstap_bgp') || is_file('/etc/rc.d/dnstap_bgp'))) {
        return true;
    }
    exec('/usr/sbin/pkg info -e os-dnstap-bgp >/dev/null 2>&1', $out, $rc);
    return $rc === 0 && is_file('/usr/local/etc/rc.d/dnstap_bgp');
}

function xray_dnstap_bgp_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return (string)($cfg->OPNsense->xray->general->dnstap_bgp_enabled ?? '0') === '1';
}

function xray_sysrc_dnstap_bgp_enable(bool $enable): void
{
    $v = $enable ? 'YES' : 'NO';
    // Broken leftover in /etc/rc.conf wins over rc.conf.d and breaks checkyesno.
    xray_rc_conf_delete_var('/etc/rc.conf', 'dnstap_bgp_enable');
    xray_sysrc_set('dnstap_bgp_enable', $v, '/usr/local/etc/rc.conf.d/dnstap_bgp');
}

function xray_dnstap_bgp_service(string $verb): void
{
    if (!xray_dnstap_bgp_installed()) {
        echo "dnstap_bgp: package not installed — skip {$verb}\n";
        return;
    }
    exec('/usr/sbin/service dnstap_bgp ' . escapeshellarg($verb) . ' 2>&1');
}

/** General Apply: Enable DNStap BGP → autostart + start; иначе стоп. Без пакета — ничего не трогаем. */
function xray_dnstap_apply_general(): void
{
    if (!xray_dnstap_bgp_installed()) {
        echo "dnstap_bgp: os-dnstap-bgp is not installed — skip start/stop\n";
        return;
    }
    if (!xray_dnstap_bgp_enabled()) {
        xray_sysrc_dnstap_bgp_enable(false);
        xray_dnstap_unbound_deactivate();
        echo "dnstap_bgp: off, enable=NO, Unbound DNSTap include removed\n";
        return;
    }
    xray_sysrc_dnstap_bgp_enable(true);
    xray_dnstap_unbound_activate();
    echo "dnstap_bgp: on, enable=YES, Unbound DNSTap include applied\n";
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
    case 'start_boot':
        // Boot hook only: start daemons + light TUN setup; defer birdsync/BGP apply.
        $exitCode = 0;
        if ($inst_uuid !== '') {
            $c = xray_get_config($inst_uuid);
            if (empty($c) || !$c['enabled']) {
                echo "Xray is disabled or instance not found.\n";
            } else {
                $exitCode = do_start($c) ? 0 : 1;
            }
        } else {
            echo "start_boot requires instance uuid\n";
            $exitCode = 1;
        }
        if (function_exists('xray_bird_sync_deferred')) {
            xray_bird_sync_deferred();
        }
        exit($exitCode);

    case 'start':
        $exitCode = 0;
        $bootLight = function_exists('xray_boot_light_active') && xray_boot_light_active();
        if ($inst_uuid !== '') {
            $c = xray_get_config($inst_uuid);
            if (empty($c) || !$c['enabled']) {
                echo "Xray is disabled or instance not found.\n";
            } else {
                $exitCode = do_start($c) ? 0 : 1;
            }
        } else {
            $all = xray_get_all_instances();
            if (empty($all)) {
                echo "No instances configured.\n";
            } else {
                $anyFailed = false;
                foreach ($all as $uuid => $c) {
                    if (!$c['enabled']) continue;
                    if (!do_start($c)) {
                        $anyFailed = true;
                    }
                }
                $exitCode = $anyFailed ? 1 : 0;
            }
        }
        if ($bootLight) {
            // OPNsense service widget may call start during boot — stay light.
            echo "start: boot light — defer BGP/DNStap/birdsync\n";
            if (function_exists('xray_bird_sync_deferred')) {
                xray_bird_sync_deferred();
            }
            exit($exitCode);
        }
        // Same as reconfigure: honor General → Enable BGP / DNStap (install Step 9 uses start).
        if (function_exists('xray_gateways_ensure_all')) {
            xray_gateways_ensure_all();
        }
        xray_bird_apply_general_bgp();
        xray_dnstap_apply_general();
        xray_bird_sync_async();
        exit($exitCode);

    case 'stop':
        if ($inst_uuid !== '') {
            do_stop($inst_uuid);
        } else {
            foreach (array_keys(xray_get_all_instances()) as $uuid) {
                do_stop($uuid);
            }
        }
        xray_bird_sync_async();
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
        xray_bird_sync_async();
        break;

    case 'reconfigure':
        // Fast path for GUI Apply: stop/start all instances can exceed nginx 60s → HTTP 504.
        // Schedule the real work and return OK immediately so the Apply banner clears.
        $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : '/usr/local/bin/php';
        if (!is_executable($php)) {
            $php = '/usr/local/bin/php';
        }
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' reconfigure_sync';
        if ($inst_uuid !== '') {
            $cmd .= ' ' . escapeshellarg($inst_uuid);
        }
        exec($cmd . ' >>/tmp/xray_reconfigure.log 2>&1 &');
        echo "OK\n";
        exit(0);

    case 'reconfigure_sync':
        // Actual stop/start (called in background from reconfigure, or manually).
        $exitCode = 0;
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                if (do_start($c)) {
                    echo "OK\n";
                } else {
                    echo "ERROR: Failed to start Xray services for instance {$inst_uuid}.\n";
                    $exitCode = 1;
                }
            } else {
                echo "Xray disabled — services stopped.\n";
            }
            if (function_exists('xray_gateways_ensure_all')) {
                xray_gateways_ensure_all();
            }
            xray_bird_sync_async();
            exit($exitCode);
        }
        $all        = xray_get_all_instances();
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
            xray_bird_apply_general_bgp();
            xray_dnstap_apply_general();
            xray_bird_sync_async();
            exit(1);
        }
        echo "OK\n";
        xray_bird_apply_general_bgp();
        xray_dnstap_apply_general();
        xray_bird_sync_async();
        exit(0);

    case 'birdhold':
        xray_bird_hold();
        break;

    case 'birdsync':
        xray_bird_sync();
        break;

    case 'birdsync_postboot':
        // One-shot after boot delay: clear light flag, filter reload once, full birdsync.
        echo "==> birdsync_postboot\n";
        if (function_exists('xray_boot_light_end')) {
            xray_boot_light_end();
        }
        @unlink(defined('XRAY_BIRDSYNC_DEFERRED_PID') ? XRAY_BIRDSYNC_DEFERRED_PID : '/var/run/xray_birdsync_deferred.pid');
        exec('/usr/local/sbin/configctl filter reload 2>&1', $filtOut, $filtRc);
        echo 'filter reload rc=' . $filtRc
            . ($filtOut !== [] ? (' ' . implode('; ', $filtOut)) : '') . "\n";
        if (function_exists('xray_gateways_ensure_all')) {
            xray_gateways_ensure_all();
        }
        if (!function_exists('xray_wan_routes_sync_all')) {
            $wr = __DIR__ . '/xray-wan-static-routes.php';
            if (is_readable($wr)) {
                require_once $wr;
            }
        }
        if (function_exists('xray_wan_routes_sync_all')) {
            xray_wan_routes_sync_all(true);
        }
        xray_bird_write_peers();
        xray_static_apply_general();
        xray_bird_sync();
        if (function_exists('xray_dnstap_apply_general')) {
            xray_dnstap_apply_general();
        }
        echo "birdsync_postboot: done\n";
        break;

    case 'bgpwrite':
        xray_bird_write_peers();
        xray_bird_reload_config();
        echo "OK\n";
        break;

    case 'bgprestart':
        xray_bird_write_peers();
        if (!xray_bird_gated_start('onerestart')) {
            echo "ERROR: BIRD not restarted — no tun2socks with HTTP success\n";
            exit(1);
        }
        echo "OK\n";
        break;

    case 'bgpstatus':
        echo json_encode(xray_bgp_peers_runtime_status()) . "\n";
        break;

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
            if (!xray_validate_ip_stack($c, $inst_uuid)) {
                exit(1);
            }
            $config = xray_build_config_array($c);
            if ($config === null) {
                exit(1);
            }
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $json = xray_normalize_transport($json);
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

    case 'delete':
        if ($inst_uuid === '') {
            echo "ERROR: instance UUID required for delete\n";
            exit(1);
        }
        $ok = do_delete($inst_uuid);
        xray_bird_sync_async();
        exit($ok ? 0 : 1);

    case 'version':
        $ver = file_exists(XRAY_VERSION_FILE) ? trim(file_get_contents(XRAY_VERSION_FILE)) : 'unknown';
        echo json_encode(['version' => $ver]) . "\n";
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}
