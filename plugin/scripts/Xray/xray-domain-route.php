#!/usr/local/bin/php
<?php
/**
 * Проверка маршрута домена или IP в BIRD: resolv (для имени) → birdcl show route for <ip> all.
 * JSON для вкладки Domains. Для прямого IP колонка Domain пустая.
 */

$xray_config_inc = '/usr/local/etc/inc/config.inc';
if (is_file($xray_config_inc)) {
    require_once $xray_config_inc;
} elseif (is_file('config.inc')) {
    require_once 'config.inc';
}

function xray_domain_route_birdcl(): string
{
    foreach (['/usr/local/birdcl', '/usr/local/sbin/birdcl', '/usr/local/sbin/birdc'] as $bin) {
        if (is_executable($bin)) {
            return $bin;
        }
    }
    return '';
}

function xray_domain_route_sanitize(string $raw): string
{
    $raw = trim($raw);
    $raw = preg_replace('#^https?://#i', '', $raw) ?? $raw;

    // [IPv6] или [IPv6]:port
    if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?/', $raw, $m)) {
        return strtolower($m[1]);
    }

    $raw = preg_replace('#/.*$#', '', $raw) ?? $raw;
    $raw = rtrim($raw, '.');

    if (filter_var($raw, FILTER_VALIDATE_IP) !== false) {
        return strtolower($raw);
    }

    // IPv4:port
    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $raw, $m)
        && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return $m[1];
    }

    // hostname:port
    $raw = preg_replace('/:\d+$/', '', $raw) ?? $raw;
    return strtolower(trim($raw, " \t\n\r."));
}

function xray_domain_route_is_ip(string $s): bool
{
    return filter_var($s, FILTER_VALIDATE_IP) !== false;
}

function xray_domain_route_is_ip6(string $s): bool
{
    return filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function xray_domain_route_general_if(string $key, string $default): string
{
    try {
        if (class_exists('OPNsense\\Core\\Config')) {
            $cfg = OPNsense\Core\Config::getInstance()->object();
            $v   = trim((string)($cfg->OPNsense->xray->general->{$key} ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
    } catch (Throwable $e) {
    }
    return $default;
}

function xray_domain_route_active_tun(bool $v6): string
{
    $file = '/usr/local/etc/bird/' . ($v6 ? 'active_tun_v6.inc' : 'active_tun_v4.inc');
    $sym  = $v6 ? 'ACTIVE_TUN6_IF' : 'ACTIVE_TUN4_IF';
    if (!is_readable($file)) {
        return '';
    }
    $text = (string)file_get_contents($file);
    if (preg_match('/define\s+' . preg_quote($sym, '/') . '\s*=\s*"([^"]+)"/', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

function xray_domain_route_if_label(string $dev, string $ip): string
{
    $v6    = xray_domain_route_is_ip6($ip);
    $wan4  = xray_domain_route_general_if('bgp_if_wan4', '');
    $wan6  = xray_domain_route_general_if('bgp_if_wan6', '');
    $low   = strtolower($dev);
    if ($low === 'lo0' || $low === 'lo' || $low === 'blackhole' || strncmp($low, 'blackhole', 9) === 0) {
        return 'Null';
    }
    if ($dev === $wan6 && $v6) {
        return 'WAN6';
    }
    if ($dev === $wan4 || $dev === $wan6) {
        return $v6 ? 'WAN6' : 'WAN4';
    }
    return $dev;
}

/**
 * Extract iface from a BIRD route line: "dev IF", "via IP on IF", 'on "IF"'.
 */
function xray_domain_route_extract_dev(string $line): string
{
    if (preg_match('/\bdev\s+"?([A-Za-z][A-Za-z0-9._-]*)"?/', $line, $m)) {
        return $m[1];
    }
    if (preg_match('/\bon\s+"?([A-Za-z][A-Za-z0-9._-]*)"?/', $line, $m)) {
        return $m[1];
    }
    if (preg_match('/^\s*Interface:\s+"?([A-Za-z][A-Za-z0-9._-]*)"?/i', $line, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * DNStap community class from BGP.community lines: 777=proxy, 555=direct, 888=block.
 */
function xray_domain_route_dnstap_class(string $text): string
{
    if (preg_match('/\b(\d+)\s*[,:]\s*888\b/', $text) || preg_match('/\(\s*\d+\s*,\s*888\s*\)/', $text)) {
        return 'block';
    }
    if (preg_match('/\b(\d+)\s*[,:]\s*777\b/', $text) || preg_match('/\(\s*\d+\s*,\s*777\s*\)/', $text)) {
        return 'proxy';
    }
    if (preg_match('/\b(\d+)\s*[,:]\s*555\b/', $text) || preg_match('/\(\s*\d+\s*,\s*555\s*\)/', $text)) {
        return 'direct';
    }
    return '';
}

function xray_domain_route_class_rank(string $class): int
{
    switch (strtolower($class)) {
        case 'block':
            return 3;
        case 'proxy':
            return 2;
        case 'direct':
            return 1;
        default:
            return 0;
    }
}

/**
 * Class from summarized dnstap domain lists (block > proxy > direct, parent match).
 */
function xray_domain_route_list_class(string $host): string
{
    if ($host === '' || xray_domain_route_is_ip($host)) {
        return '';
    }
    $host = strtolower(rtrim($host, '.'));
    $files = [
        'block'  => '/usr/local/etc/dnstap-bgp/block.txt',
        'proxy'  => '/usr/local/etc/dnstap-bgp/proxy.txt',
        'direct' => '/usr/local/etc/dnstap-bgp/direct.txt',
    ];
    foreach ($files as $class => $path) {
        if (!is_readable($path)) {
            continue;
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            continue;
        }
        while (($line = fgets($fh)) !== false) {
            $d = strtolower(trim($line));
            if ($d === '' || $d[0] === '#') {
                continue;
            }
            if ($host === $d || (strlen($host) > strlen($d) + 1 && substr($host, -(strlen($d) + 1)) === '.' . $d)) {
                fclose($fh);
                return $class;
            }
        }
        fclose($fh);
    }
    return '';
}

function xray_domain_route_is_host(string $s): bool
{
    if ($s === '' || strlen($s) > 253) {
        return false;
    }
    return (bool)preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/i', $s);
}

function xray_domain_route_resolve(string $host): array
{
    if (xray_domain_route_is_ip($host)) {
        return [$host];
    }
    $ips = [];
    foreach ([DNS_A, DNS_AAAA] as $type) {
        $recs = @dns_get_record($host, $type);
        if (!is_array($recs)) {
            continue;
        }
        foreach ($recs as $r) {
            if ($type === DNS_A && !empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if ($type === DNS_AAAA && !empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
    }
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        foreach ($v4 as $ip) {
            $ips[] = $ip;
        }
    }
    $ips = array_values(array_unique($ips));
    return array_slice($ips, 0, 32);
}

function xray_domain_route_tun_map(): array
{
    $map = [];
    if (!class_exists('OPNsense\\Core\\Config')) {
        return $map;
    }
    try {
        $cfg = OPNsense\Core\Config::getInstance()->object();
        $ins = $cfg->OPNsense->xray->instances ?? null;
        if (!$ins) {
            return $map;
        }
        foreach ($ins->instance as $inst) {
            if ((string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                continue;
            }
            $name = trim((string)($inst->name ?? ''));
            $map[$tun] = $name !== '' ? $name : $tun;
        }
    } catch (Throwable $e) {
        return $map;
    }
    return $map;
}

function xray_domain_route_query(string $birdcl, string $ip): string
{
    $out = [];
    exec(
        escapeshellarg($birdcl) . ' show route for ' . escapeshellarg($ip) . ' all 2>&1',
        $out
    );
    return implode("\n", $out);
}

function xray_domain_route_not_found(string $text): bool
{
    return (bool)preg_match('/network not (found|in table)/i', $text);
}

function xray_domain_route_line_active(string $line): bool
{
    return (bool)preg_match('/\]\s*\*/', $line);
}

/**
 * Разбор `show route for <ip> all`: протокол в [name …], iface (dev / via…on), * = active.
 */
function xray_domain_route_parse(string $text, string $ip): array
{
    $paths = [];
    $cur = null;
    $flush = static function () use (&$paths, &$cur): void {
        if (is_array($cur)) {
            $paths[] = $cur;
        }
        $cur = null;
    };
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        if (preg_match('/^([0-9a-fA-F:.]+\/\d+)\s+(unicast|blackhole)\s+\[(\S+)/', $line, $m)) {
            $flush();
            $dev = ($m[2] === 'blackhole') ? 'blackhole' : xray_domain_route_extract_dev($line);
            $cur = [
                'prefix'    => $m[1],
                'peer'      => $m[3],
                'dev'       => $dev,
                'active'    => xray_domain_route_line_active($line),
                'community' => '',
            ];
            continue;
        }
        if (preg_match('/^\s+(unicast|blackhole)\s+\[(\S+)/', $line, $m)) {
            $flush();
            $dev = ($m[1] === 'blackhole') ? 'blackhole' : xray_domain_route_extract_dev($line);
            $cur = [
                'prefix'    => $ip,
                'peer'      => $m[2],
                'dev'       => $dev,
                'active'    => xray_domain_route_line_active($line),
                'community' => '',
            ];
            continue;
        }
        if ($cur === null) {
            continue;
        }
        $dev = xray_domain_route_extract_dev($line);
        if ($dev !== '') {
            if ($cur['dev'] === '' || $cur['dev'] === 'blackhole') {
                $cur['dev'] = $dev;
            } elseif (preg_match('/^\s+dev\s+/', $line)) {
                // Prefer explicit "dev IF" over earlier "via … on IF"
                $cur['dev'] = $dev;
            }
        }
        if (preg_match('/BGP\.community|community:/i', $line)
            || preg_match('/\(\s*\d+\s*,\s*\d+\s*\)/', $line)) {
            $cur['community'] .= ' ' . $line;
        }
    }
    $flush();
    return $paths;
}

$raw = isset($argv[1]) ? (string)$argv[1] : '';
$query = xray_domain_route_sanitize($raw);
$isIp = xray_domain_route_is_ip($query);
$domainLabel = $isIp ? '' : $query;

$fail = static function (string $msg) use ($query, $domainLabel): void {
    echo json_encode([
        'result' => 'failed',
        'domain' => $domainLabel,
        'query'  => $query,
        'message' => $msg,
        'rows' => [],
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
};

if ($query === '' || (!$isIp && !xray_domain_route_is_host($query))) {
    $fail('Invalid domain or IP');
}

$ips = xray_domain_route_resolve($query);
if ($ips === []) {
    $fail($isIp ? 'Invalid IP' : 'No A/AAAA records');
}

$birdcl = xray_domain_route_birdcl();
if ($birdcl === '') {
    $fail('birdcl not found');
}

$tunMap = xray_domain_route_tun_map();
$listClass = $isIp ? '' : xray_domain_route_list_class($query);
$rows = [];
$hint = '';
foreach ($ips as $ip) {
    $text = xray_domain_route_query($birdcl, $ip);
    $paths = xray_domain_route_parse($text, $ip);
    if ($paths === [] || xray_domain_route_not_found($text)) {
        $rows[] = [
            'domain'     => $domainLabel,
            'ip'         => $ip,
            'peer'       => 'N/A',
            'instance'   => 'N/A',
            'dev'        => xray_domain_route_is_ip6($ip) ? 'WAN6' : 'WAN4',
            'active'     => true,
            'class'      => '',
            'list_class' => $listClass,
        ];
        continue;
    }
    foreach ($paths as $p) {
        $dev = trim((string)($p['dev'] ?? ''));
        $peer = trim((string)($p['peer'] ?? ''));
        $v6 = xray_domain_route_is_ip6($ip);
        $dnstapClass = xray_domain_route_dnstap_class((string)($p['community'] ?? ''));
        // Empty iface used to fall back to WAN — wrong for dnstap PROXY (AS:777 → ACTIVE_TUN).
        if ($dev === '' && strcasecmp($peer, 'dnstap') === 0) {
            if ($dnstapClass === 'block') {
                $dev = 'blackhole';
            } elseif ($dnstapClass === 'direct') {
                $dev = $v6
                    ? xray_domain_route_general_if('bgp_if_wan6', '')
                    : xray_domain_route_general_if('bgp_if_wan4', '');
            } elseif ($dnstapClass === 'proxy' || $dnstapClass === '') {
                // PROXY (777) or unknown dnstap community → active proxy TUN
                $dev = xray_domain_route_active_tun($v6);
            }
        }
        $iface = $dev !== ''
            ? xray_domain_route_if_label($dev, $ip)
            : ($v6 ? 'WAN6' : 'WAN4');
        // If BIRD still shows WAN for a PROXY community route, prefer ACTIVE_TUN label.
        if ($dnstapClass === 'proxy' && ($iface === 'WAN4' || $iface === 'WAN6')) {
            $tun = xray_domain_route_active_tun($v6);
            if ($tun !== '') {
                $dev = $tun;
                $iface = xray_domain_route_if_label($tun, $ip);
            }
        }
        $inst = 'N/A';
        if ($dev !== '' && isset($tunMap[$dev])) {
            $inst = $tunMap[$dev];
        }
        if ($listClass !== '' && $dnstapClass !== ''
            && xray_domain_route_class_rank($listClass) > xray_domain_route_class_rank($dnstapClass)
            && $hint === '') {
            $hint = 'List says ' . strtoupper($listClass)
                . ' but BIRD has ' . strtoupper($dnstapClass)
                . ' for this IP (CDN/shared IP or stale dnstap cache). '
                . 'Query the domain via Unbound or: service dnstap_bgp reload';
        }
        $rows[] = [
            'domain'     => $domainLabel,
            'ip'         => $ip,
            'peer'       => $peer !== '' ? $peer : 'N/A',
            'instance'   => $inst,
            'dev'        => $iface,
            'active'     => !empty($p['active']),
            'class'      => $dnstapClass !== '' ? strtoupper($dnstapClass) : '',
            'list_class' => $listClass !== '' ? strtoupper($listClass) : '',
        ];
    }
}

echo json_encode([
    'result'     => 'ok',
    'domain'     => $domainLabel,
    'query'      => $query,
    'list_class' => $listClass !== '' ? strtoupper($listClass) : '',
    'hint'       => $hint,
    'rows'       => $rows,
], JSON_UNESCAPED_SLASHES) . "\n";
