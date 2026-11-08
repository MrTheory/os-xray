#!/usr/local/bin/php
<?php
/**
 * Static IP list URLs → intermediate prefix .inc → final routes_static_v{4,6}_apply.inc
 *
 * Sources (comma-separated prefixes, one per line):
 *   routes_static_v{4,6}_{PROXY,DIRECT,BLOCK}.inc          — IP list URLs
 *   routes_static_v{4,6}_{PROXY,DIRECT,BLOCK}_geodat.inc   — geoip.dat categories
 *   routes_static_v{4,6}_{PROXY,DIRECT,BLOCK}_extra.inc    — ручные Extra IP (вкладка Domain)
 * Final (route statements, included by bird.conf):
 *   routes_static_v{4,6}_apply.inc
 *
 *   xray-static-conf.php get
 *   xray-static-conf.php write
 *   xray-static-conf.php fetchone   — ✔: download one URL into cache
 *   xray-static-conf.php fetch      — daily: refresh enabled URL caches
 *   xray-static-conf.php extra      — add/remove one Extra IP and rebuild
 *   xray-static-conf.php extrawrite — replace Extra IP lists and rebuild (Domain Apply)
 *   xray-static-conf.php geodatcats
 *   xray-static-conf.php seed       — default geodat URL + categories
 */

$xray_config_inc = '/usr/local/etc/inc/config.inc';
if (is_file($xray_config_inc)) {
    require_once $xray_config_inc;
} elseif (is_file('config.inc')) {
    require_once 'config.inc';
}
require_once __DIR__ . '/xray-dnstap-geosite.php';
require_once __DIR__ . '/xray-static-geoip.php';
require_once __DIR__ . '/xray-bird-peers.php';

const XRAY_STATIC_STAGED = '/tmp/xray_static_write.json';
const XRAY_STATIC_FETCHONE_STAGED = '/tmp/xray_static_fetchone.json';
const XRAY_STATIC_EXTRA_STAGED = '/tmp/xray_static_extra.json';
const XRAY_STATIC_GEODAT_STAGED = '/tmp/xray_static_geodat.json';
const XRAY_STATIC_MAX_BODY = 67108864;
const XRAY_STATIC_BIRD_DIR = '/usr/local/etc/bird';
const XRAY_STATIC_CACHE_DIR = '/usr/local/etc/bird/static-cache';
const XRAY_STATIC_FILES = [
    'proxy_urls'       => '/usr/local/etc/bird/static-proxy-urls.json',
    'direct_urls'      => '/usr/local/etc/bird/static-direct-urls.json',
    'block_urls'       => '/usr/local/etc/bird/static-block-urls.json',
    'geodat_urls'      => '/usr/local/etc/bird/static-geodat-urls.json',
    // legacy plain-text lists (migrated on read)
    'proxy_urls_txt'   => '/usr/local/etc/bird/static-proxy-urls.txt',
    'direct_urls_txt'  => '/usr/local/etc/bird/static-direct-urls.txt',
    'block_urls_txt'   => '/usr/local/etc/bird/static-block-urls.txt',
    'geodat_urls_txt'  => '/usr/local/etc/bird/static-geodat-urls.txt',
    'v4_proxy'         => '/usr/local/etc/bird/routes_static_v4_PROXY.inc',
    'v4_direct'        => '/usr/local/etc/bird/routes_static_v4_DIRECT.inc',
    'v4_block'         => '/usr/local/etc/bird/routes_static_v4_BLOCK.inc',
    'v6_proxy'         => '/usr/local/etc/bird/routes_static_v6_PROXY.inc',
    'v6_direct'        => '/usr/local/etc/bird/routes_static_v6_DIRECT.inc',
    'v6_block'         => '/usr/local/etc/bird/routes_static_v6_BLOCK.inc',
    'v4_proxy_geodat'  => '/usr/local/etc/bird/routes_static_v4_PROXY_geodat.inc',
    'v4_direct_geodat' => '/usr/local/etc/bird/routes_static_v4_DIRECT_geodat.inc',
    'v4_block_geodat'  => '/usr/local/etc/bird/routes_static_v4_BLOCK_geodat.inc',
    'v6_proxy_geodat'  => '/usr/local/etc/bird/routes_static_v6_PROXY_geodat.inc',
    'v6_direct_geodat' => '/usr/local/etc/bird/routes_static_v6_DIRECT_geodat.inc',
    'v6_block_geodat'  => '/usr/local/etc/bird/routes_static_v6_BLOCK_geodat.inc',
    'v4_proxy_extra'   => '/usr/local/etc/bird/routes_static_v4_PROXY_extra.inc',
    'v4_direct_extra'  => '/usr/local/etc/bird/routes_static_v4_DIRECT_extra.inc',
    'v4_block_extra'   => '/usr/local/etc/bird/routes_static_v4_BLOCK_extra.inc',
    'v6_proxy_extra'   => '/usr/local/etc/bird/routes_static_v6_PROXY_extra.inc',
    'v6_direct_extra'  => '/usr/local/etc/bird/routes_static_v6_DIRECT_extra.inc',
    'v6_block_extra'   => '/usr/local/etc/bird/routes_static_v6_BLOCK_extra.inc',
    'v4_apply'         => '/usr/local/etc/bird/routes_static_v4_apply.inc',
    'v6_apply'         => '/usr/local/etc/bird/routes_static_v6_apply.inc',
    'protocols'        => '/usr/local/etc/bird/static_protocols.inc',
];

/** Intermediate prefix sources per family → keys in XRAY_STATIC_FILES */
const XRAY_STATIC_SOURCE_KEYS = [
    'ipv4' => [
        'proxy'  => ['v4_proxy', 'v4_proxy_geodat', 'v4_proxy_extra'],
        'direct' => ['v4_direct', 'v4_direct_geodat', 'v4_direct_extra'],
        'block'  => ['v4_block', 'v4_block_geodat', 'v4_block_extra'],
    ],
    'ipv6' => [
        'proxy'  => ['v6_proxy', 'v6_proxy_geodat', 'v6_proxy_extra'],
        'direct' => ['v6_direct', 'v6_direct_geodat', 'v6_direct_extra'],
        'block'  => ['v6_block', 'v6_block_geodat', 'v6_block_extra'],
    ],
];

/** Default Geodat IP list URL (geoip.dat) for Static tab. */
const XRAY_STATIC_DEFAULT_GEODAT_URLS = [
    'https://cdn.jsdelivr.net/gh/hydraponique/roscomvpn-geoip/release/geoip.dat',
];
/** Default geoip category → proxy|direct|block for XRAY_STATIC_DEFAULT_GEODAT_URLS[0]. */
const XRAY_STATIC_DEFAULT_GEODAT_CATEGORIES = [
    'direct'    => 'direct',
    'whitelist' => 'direct',
];

function xray_static_read_file(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }
    return (string)file_get_contents($path);
}

function xray_static_write_file(string $path, string $body): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    if (file_put_contents($path, $body) === false) {
        return false;
    }
    @chmod($path, 0644);
    return true;
}

function xray_static_row_enabled($row): bool
{
    if (!is_array($row)) {
        return false;
    }
    if (!array_key_exists('enabled', $row)) {
        return true; // legacy rows without flag
    }
    $v = $row['enabled'];
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string)$v));
    return $s === '1' || $s === 'on' || $s === 'true' || $s === 'yes';
}

function xray_static_rows_from_post($raw): array
{
    if (is_array($raw)) {
        $rows = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['url']) && !isset($item['value'])) {
                $item['value'] = $item['url'];
            }
            // Saved JSON is {"url","enabled"} without "key" — must still load.
            if (isset($item['key']) || isset($item['value']) || isset($item['url']) || isset($item['name'])) {
                $key = (string)($item['key'] ?? $item['name'] ?? 'url');
                $val = (string)($item['value'] ?? $item['action'] ?? $item['url'] ?? '');
                $en  = array_key_exists('enabled', $item)
                    ? (xray_static_row_enabled($item) ? '1' : '0')
                    : '1'; // legacy rows without flag
                $rows[] = [
                    'key'     => $key !== '' ? $key : 'url',
                    'value'   => $val,
                    'enabled' => $en,
                ];
            }
        }
        return $rows;
    }
    if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return xray_static_rows_from_post($j);
        }
    }
    return [];
}

function xray_static_parse_urls_txt(string $text): array
{
    $rows = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $u = trim($line);
        if ($u === '' || $u[0] === '#') {
            continue;
        }
        if (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        $rows[] = ['key' => 'url', 'value' => $u, 'enabled' => '1'];
    }
    return $rows;
}

/** @return array<int,array{key:string,value:string,enabled:string}> */
function xray_static_load_url_rows(string $kind): array
{
    $jsonKey = $kind . '_urls';
    $txtKey  = $kind . '_urls_txt';
    $jsonPath = XRAY_STATIC_FILES[$jsonKey] ?? '';
    $txtPath  = XRAY_STATIC_FILES[$txtKey] ?? '';
    if ($jsonPath !== '' && is_readable($jsonPath)) {
        $j = json_decode(xray_static_read_file($jsonPath), true);
        if (is_array($j)) {
            return xray_static_rows_from_post($j);
        }
    }
    if ($txtPath !== '' && is_readable($txtPath)) {
        return xray_static_parse_urls_txt(xray_static_read_file($txtPath));
    }
    return [];
}

function xray_static_render_urls_json(array $rows): string
{
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $u = trim((string)($row['value'] ?? $row['url'] ?? ''));
        if ($u === '' || !preg_match('#^https?://#i', $u) || isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $out[] = [
            'key'     => 'url',
            'url'     => $u,
            'value'   => $u,
            'enabled' => xray_static_row_enabled($row) ? '1' : '0',
        ];
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

function xray_static_save_url_rows(string $kind, array $rows): void
{
    $jsonKey = $kind . '_urls';
    if (!isset(XRAY_STATIC_FILES[$jsonKey])) {
        return;
    }
    xray_static_write_file(XRAY_STATIC_FILES[$jsonKey], xray_static_render_urls_json($rows));
}

function xray_static_url_list(array $rows, bool $enabledOnly = false): array
{
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        if ($enabledOnly && !xray_static_row_enabled($row)) {
            continue;
        }
        $u = trim((string)($row['value'] ?? $row['url'] ?? ''));
        if ($u === '' || !preg_match('#^https?://#i', $u) || isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $out[] = $u;
    }
    return $out;
}

function xray_static_has_urls(array $rows, bool $enabledOnly = false): bool
{
    return xray_static_url_list($rows, $enabledOnly) !== [];
}

function xray_static_url_id(string $url): string
{
    return sha1(strtolower(rtrim(trim($url), '/')));
}

function xray_static_cache_path(string $kind, string $url): string
{
    $kind = preg_replace('/[^a-z]/', '', strtolower($kind)) ?: 'proxy';
    $ext = ($kind === 'geodat') ? '.dat' : '.txt';
    return XRAY_STATIC_CACHE_DIR . '/' . $kind . '/' . xray_static_url_id($url) . $ext;
}

function xray_static_cache_write(string $kind, string $url, string $body): bool
{
    return xray_static_write_file(xray_static_cache_path($kind, $url), $body);
}

function xray_static_cache_read(string $kind, string $url): string
{
    return xray_static_read_file(xray_static_cache_path($kind, $url));
}

function xray_static_upsert_url_row(array $rows, string $url, ?bool $enabled = null): array
{
    $url = trim($url);
    $found = false;
    foreach ($rows as &$row) {
        $u = trim((string)($row['value'] ?? $row['url'] ?? ''));
        if (strcasecmp($u, $url) === 0) {
            $row['key'] = 'url';
            $row['value'] = $url;
            if ($enabled !== null) {
                $row['enabled'] = $enabled ? '1' : '0';
            } elseif (!isset($row['enabled'])) {
                $row['enabled'] = '0';
            }
            $found = true;
            break;
        }
    }
    unset($row);
    if (!$found) {
        $rows[] = [
            'key'     => 'url',
            'value'   => $url,
            'enabled' => ($enabled === null) ? '0' : ($enabled ? '1' : '0'),
        ];
    }
    return $rows;
}

function xray_static_fetch_url(string $url): string
{
    $tmp = tempnam('/tmp', 'xray_static_dl_');
    if ($tmp === false) {
        return '';
    }
    $cmd = '/usr/bin/fetch -q -o ' . escapeshellarg($tmp) . ' -T 90 ' . escapeshellarg($url);
    exec($cmd . ' 2>&1', $out, $rc);
    if ($rc !== 0 || !is_readable($tmp)) {
        @unlink($tmp);
        $cmd = '/usr/local/bin/curl -fsSL --max-time 90 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
        exec($cmd . ' 2>&1', $out2, $rc2);
        if ($rc2 !== 0 || !is_readable($tmp)) {
            @unlink($tmp);
            return '';
        }
    }
    $size = (int)filesize($tmp);
    if ($size <= 0 || $size > XRAY_STATIC_MAX_BODY) {
        @unlink($tmp);
        return '';
    }
    $body = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $body;
}

/**
 * Normalize a token to IPv4/IPv6 CIDR or empty.
 * Accepts: 1.2.3.4, 1.2.3.0/24, 2001:db8::1, 2001:db8::/32
 *
 * @return array{cidr:string,v6:bool}|null
 */
function xray_static_parse_prefix(string $raw): ?array
{
    $s = trim($raw);
    $s = preg_replace('/^\uFEFF/', '', $s) ?? $s;
    if ($s === '' || $s[0] === '#' || $s[0] === ';') {
        return null;
    }
    // strip optional trailing comma / bird keywords
    $s = rtrim($s, ",; \t");
    $s = preg_replace('/^(route|net)\s+/i', '', $s) ?? $s;
    $s = preg_replace('/\s+(via|blackhole|unreachable|prohibit).*$/i', '', $s) ?? $s;
    $s = trim($s, " \t\"'");
    if ($s === '') {
        return null;
    }
    if (strpos($s, '/') === false) {
        if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['cidr' => $s . '/32', 'v6' => false];
        }
        if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ['cidr' => $s . '/128', 'v6' => true];
        }
        return null;
    }
    if (!preg_match('#^([^/]+)/(\d{1,3})$#', $s, $m)) {
        return null;
    }
    $ip = $m[1];
    $p = (int)$m[2];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if ($p < 0 || $p > 32) {
            return null;
        }
        return ['cidr' => $ip . '/' . $p, 'v6' => false];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if ($p < 0 || $p > 128) {
            return null;
        }
        return ['cidr' => $ip . '/' . $p, 'v6' => true];
    }
    return null;
}

/**
 * @return array{v4:array<string,true>,v6:array<string,true>}
 */
function xray_static_parse_prefix_text(string $text): array
{
    $v4 = [];
    $v6 = [];
    foreach (preg_split("/\r\n|\n|\r|,/", $text) as $line) {
        $p = xray_static_parse_prefix($line);
        if ($p === null) {
            continue;
        }
        if ($p['v6']) {
            $v6[$p['cidr']] = true;
        } else {
            $v4[$p['cidr']] = true;
        }
    }
    return ['v4' => $v4, 'v6' => $v6];
}

/**
 * Intermediate prefix list: one prefix per line, comma except last. Empty → "#\n"
 */
function xray_static_render_prefix_list(array $cidrs): string
{
    $list = [];
    $seen = [];
    foreach ($cidrs as $c) {
        $c = trim((string)$c);
        if ($c === '' || isset($seen[$c])) {
            continue;
        }
        $seen[$c] = true;
        $list[] = $c;
    }
    if ($list === []) {
        return "#\n";
    }
    sort($list, SORT_STRING);
    $n = count($list);
    $lines = [];
    foreach ($list as $i => $c) {
        $lines[] = ($i === $n - 1) ? $c : ($c . ',');
    }
    return implode("\n", $lines) . "\n";
}

/**
 * Load prefixes from one intermediate source file.
 *
 * @return array<string,true>
 */
function xray_static_load_prefix_file(string $path): array
{
    $parsed = xray_static_parse_prefix_text(xray_static_read_file($path));
    // parse_prefix_text returns both v4 and v6; callers pick by file family
    return $parsed['v4'] + $parsed['v6'];
}

/**
 * Канонический CIDR: сжатый адрес и обнулённые хостовые биты.
 * 1.2.3.5/24 → 1.2.3.0/24, 2001:0db8::1 → 2001:db8::1/128.
 *
 * @param array{cidr:string,v6:bool} $p
 * @return array{cidr:string,v6:bool}
 */
function xray_static_canonical_prefix(array $p): array
{
    $slash = strrpos($p['cidr'], '/');
    if ($slash === false) {
        return $p;
    }
    $ip = substr($p['cidr'], 0, $slash);
    $len = (int)substr($p['cidr'], $slash + 1);
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return $p;
    }
    $max = strlen($bin) * 8;
    if ($len < 0 || $len > $max) {
        return $p;
    }
    $bytes = strlen($bin);
    $full = intdiv($len, 8);
    $rem = $len % 8;
    $masked = str_repeat("\0", $bytes);
    if ($full > 0) {
        $masked = substr($bin, 0, $full) . substr($masked, $full);
    }
    if ($rem > 0 && $full < $bytes) {
        $maskByte = chr(ord($bin[$full]) & (0xFF << (8 - $rem)) & 0xFF);
        $masked = substr($masked, 0, $full) . $maskByte . substr($masked, $full + 1);
    }
    $norm = @inet_ntop($masked);
    if ($norm === false || $norm === '') {
        return $p;
    }
    if ($p['v6']) {
        $norm = strtolower($norm);
    }
    return ['cidr' => $norm . '/' . $len, 'v6' => $p['v6']];
}

/**
 * Снять скобки у IPv6 ([2001:db8::1] или [2001:db8::1]/64) перед разбором.
 */
function xray_static_strip_ip_brackets(string $raw): string
{
    $s = trim($raw);
    if (preg_match('/^\[([^\]]+)\](?:\/(\d{1,3}))?$/', $s, $m)) {
        $s = $m[1] . (isset($m[2]) && $m[2] !== '' ? '/' . $m[2] : '');
    }
    return trim($s);
}

/**
 * Extra-префиксы одного вида, разложенные по семьям.
 * Префикс не из «своего» файла попадает в правильную семью.
 *
 * @return array{v4:array<string,true>,v6:array<string,true>}
 */
function xray_static_extra_sets(string $kind): array
{
    $v4 = [];
    $v6 = [];
    foreach (['v4', 'v6'] as $fam) {
        $key = $fam . '_' . $kind . '_extra';
        if (!isset(XRAY_STATIC_FILES[$key])) {
            continue;
        }
        $parsed = xray_static_parse_prefix_text(xray_static_read_file(XRAY_STATIC_FILES[$key]));
        foreach ($parsed['v4'] as $c => $_) {
            $canon = xray_static_canonical_prefix(['cidr' => $c, 'v6' => false]);
            $v4[$canon['cidr']] = true;
        }
        foreach ($parsed['v6'] as $c => $_) {
            $canon = xray_static_canonical_prefix(['cidr' => $c, 'v6' => true]);
            $v6[$canon['cidr']] = true;
        }
    }
    return ['v4' => $v4, 'v6' => $v6];
}

/**
 * @return array{proxy:string[],direct:string[],block:string[]}
 */
function xray_static_extra_lists(): array
{
    $out = [];
    foreach (['proxy', 'direct', 'block'] as $kind) {
        $sets = xray_static_extra_sets($kind);
        $v4 = array_keys($sets['v4']);
        $v6 = array_keys($sets['v6']);
        sort($v4, SORT_STRING);
        sort($v6, SORT_STRING);
        $out[$kind] = array_values(array_merge($v4, $v6));
    }
    return $out;
}

/**
 * Записать extra-файлы одного вида. Пустой набор становится "#\n".
 *
 * @param array{v4:array<string,true>,v6:array<string,true>} $sets
 */
function xray_static_extra_save(string $kind, array $sets): bool
{
    $ok4 = xray_static_write_file(
        XRAY_STATIC_FILES['v4_' . $kind . '_extra'],
        xray_static_render_prefix_list(array_keys($sets['v4']))
    );
    $ok6 = xray_static_write_file(
        XRAY_STATIC_FILES['v6_' . $kind . '_extra'],
        xray_static_render_prefix_list(array_keys($sets['v6']))
    );
    return $ok4 && $ok6;
}

/**
 * Совпадает ли файл на диске с каноническим набором (пустой файл = "#\n").
 *
 * @param array{v4:array<string,true>,v6:array<string,true>} $sets
 */
function xray_static_extra_disk_matches(string $kind, array $sets): bool
{
    $want4 = xray_static_render_prefix_list(array_keys($sets['v4']));
    $want6 = xray_static_render_prefix_list(array_keys($sets['v6']));
    $have4 = xray_static_read_file(XRAY_STATIC_FILES['v4_' . $kind . '_extra']);
    $have6 = xray_static_read_file(XRAY_STATIC_FILES['v6_' . $kind . '_extra']);
    if ($have4 === '') {
        $have4 = "#\n";
    }
    if ($have6 === '') {
        $have6 = "#\n";
    }
    return $have4 === $want4 && $have6 === $want6;
}

/**
 * Добавить или удалить один Extra IP и при изменении пересобрать static.
 *
 * @return array{ok:bool,message:string,changed:bool,prefix:string,kind:string}
 */
function xray_static_extra_mutate(string $action, string $kind, string $prefix): array
{
    $action = strtolower(trim($action));
    $kind = strtolower(trim($kind));
    $empty = ['ok' => false, 'message' => '', 'changed' => false, 'prefix' => '', 'kind' => $kind];
    if (!in_array($action, ['add', 'remove'], true)) {
        $empty['message'] = 'action must be add or remove';
        return $empty;
    }
    if (!in_array($kind, ['proxy', 'direct', 'block'], true)) {
        $empty['message'] = 'kind must be proxy, direct or block';
        return $empty;
    }
    $parsed = xray_static_parse_prefix(xray_static_strip_ip_brackets($prefix));
    if ($parsed === null) {
        $empty['message'] = 'Enter an IPv4/IPv6 address or subnet';
        return $empty;
    }
    $parsed = xray_static_canonical_prefix($parsed);
    $cidr = $parsed['cidr'];
    $fam = $parsed['v6'] ? 'v6' : 'v4';
    $sets = xray_static_extra_sets($kind);
    $has = isset($sets[$fam][$cidr]);
    if ($action === 'add') {
        if ($has && xray_static_extra_disk_matches($kind, $sets)) {
            return [
                'ok' => true,
                'message' => 'Prefix already in the extra list',
                'changed' => false,
                'prefix' => $cidr,
                'kind' => $kind,
            ];
        }
        $sets[$fam][$cidr] = true;
        $message = $has
            ? 'Prefix already in the extra list'
            : 'Added prefix and rebuilt static routes';
    } else {
        if (!$has) {
            return [
                'ok' => true,
                'message' => 'Prefix is not in the extra list',
                'changed' => false,
                'prefix' => $cidr,
                'kind' => $kind,
            ];
        }
        unset($sets[$fam][$cidr]);
        $message = 'Removed prefix and rebuilt static routes';
    }
    if (!xray_static_extra_save($kind, $sets)) {
        return [
            'ok' => false,
            'message' => 'Cannot write extra prefix file',
            'changed' => false,
            'prefix' => $cidr,
            'kind' => $kind,
        ];
    }
    return [
        'ok' => true,
        'message' => $message,
        'changed' => true,
        'prefix' => $cidr,
        'kind' => $kind,
    ];
}

/**
 * Разобрать список Extra IP одного вида в канонические v4/v6.
 *
 * @param array<int,mixed> $rawList
 * @return array{v4:array<string,true>,v6:array<string,true>,bad:string[]}
 */
function xray_static_extra_collect(array $rawList): array
{
    $v4 = [];
    $v6 = [];
    $bad = [];
    foreach ($rawList as $raw) {
        if (is_array($raw)) {
            $raw = $raw['value'] ?? $raw['prefix'] ?? '';
        }
        $s = trim((string)$raw);
        if ($s === '') {
            continue;
        }
        $parsed = xray_static_parse_prefix(xray_static_strip_ip_brackets($s));
        if ($parsed === null) {
            $bad[] = $s;
            continue;
        }
        $parsed = xray_static_canonical_prefix($parsed);
        if ($parsed['v6']) {
            $v6[$parsed['cidr']] = true;
        } else {
            $v4[$parsed['cidr']] = true;
        }
    }
    return ['v4' => $v4, 'v6' => $v6, 'bad' => $bad];
}

/**
 * Заменить все Extra IP списки. Сборку BIRD делает вызывающий (Apply).
 *
 * @param array{proxy?:array,direct?:array,block?:array} $lists
 * @return array{ok:bool,message:string}
 */
function xray_static_extra_replace(array $lists): array
{
    $parsed = [];
    foreach (['proxy', 'direct', 'block'] as $kind) {
        $items = $lists[$kind] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $col = xray_static_extra_collect($items);
        if ($col['bad'] !== []) {
            return [
                'ok' => false,
                'message' => 'Invalid prefix: ' . $col['bad'][0],
            ];
        }
        $parsed[$kind] = $col;
    }
    foreach (['proxy', 'direct', 'block'] as $kind) {
        if (!xray_static_extra_save($kind, ['v4' => $parsed[$kind]['v4'], 'v6' => $parsed[$kind]['v6']])) {
            return [
                'ok' => false,
                'message' => 'Cannot write extra prefix file',
            ];
        }
    }
    return [
        'ok' => true,
        'message' => 'Extra IP lists saved and static routes rebuilt',
    ];
}

/**
 * Счётчики префиксов в итоговом apply.inc (после приоритета BLOCK > PROXY > DIRECT).
 *
 * @return array{proxy4:int,proxy6:int,direct4:int,direct6:int,block4:int,block6:int}
 */
function xray_static_apply_counts(): array
{
    $m4 = xray_static_merge_sources('ipv4');
    $m6 = xray_static_merge_sources('ipv6');
    return [
        'proxy4'  => count($m4['proxy']),
        'proxy6'  => count($m6['proxy']),
        'direct4' => count($m4['direct']),
        'direct6' => count($m6['direct']),
        'block4'  => count($m4['block']),
        'block6'  => count($m6['block']),
    ];
}

/**
 * Merge intermediate sources for one family. Priority: BLOCK > PROXY > DIRECT.
 *
 * @return array{proxy:array<string,true>,direct:array<string,true>,block:array<string,true>}
 */
function xray_static_merge_sources(string $family): array
{
    $map = XRAY_STATIC_SOURCE_KEYS[$family] ?? null;
    if ($map === null) {
        return ['proxy' => [], 'direct' => [], 'block' => []];
    }
    $proxy = [];
    $direct = [];
    $block = [];
    foreach ($map['proxy'] as $key) {
        foreach (xray_static_load_prefix_file(XRAY_STATIC_FILES[$key]) as $c => $_) {
            $proxy[$c] = true;
        }
    }
    foreach ($map['direct'] as $key) {
        foreach (xray_static_load_prefix_file(XRAY_STATIC_FILES[$key]) as $c => $_) {
            $direct[$c] = true;
        }
    }
    foreach ($map['block'] as $key) {
        foreach (xray_static_load_prefix_file(XRAY_STATIC_FILES[$key]) as $c => $_) {
            $block[$c] = true;
        }
    }
    foreach ($block as $c => $_) {
        unset($proxy[$c], $direct[$c]);
    }
    foreach ($proxy as $c => $_) {
        unset($direct[$c]);
    }
    return ['proxy' => $proxy, 'direct' => $direct, 'block' => $block];
}

/**
 * Final apply.inc: route statements from merged sources.
 * PROXY → ACTIVE_TUN*_IF, DIRECT → IPv4_GATEWAY / IPv6 via iface|gw, BLOCK → blackhole.
 */
function xray_static_render_apply(string $family, array $proxy, array $direct, array $block): string
{
    $viaProxy = ($family === 'ipv6') ? 'ACTIVE_TUN6_IF' : 'ACTIVE_TUN4_IF';
    // IPv6 DIRECT: via "igc3" / via fe80::x%igc3 (not "device")
    $viaDirect = ($family === 'ipv6')
        ? xray_bird_ipv6_direct_via_clause()
        : 'via IPv4_GATEWAY;';
    $lines = [
        '# generated by os-xray from intermediate sources — do not edit; Static tab Apply',
    ];
    $emit = static function (array $cidrs, string $tail) use (&$lines): void {
        $list = array_keys($cidrs);
        sort($list, SORT_STRING);
        foreach ($list as $c) {
            $c = trim((string)$c);
            if ($c === '') {
                continue;
            }
            $lines[] = 'route ' . $c . ' ' . $tail;
        }
    };
    $emit($proxy, 'via ' . $viaProxy . ';');
    $emit($direct, $viaDirect);
    $emit($block, 'blackhole;');
    if (count($lines) === 1) {
        $lines[] = '#';
    }
    return implode("\n", $lines) . "\n";
}

function xray_static_enabled(): bool
{
    try {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        $v = strtolower(trim((string)($cfg->OPNsense->xray->general->static_enabled ?? '0')));
        return $v === '1' || $v === 'on' || $v === 'true' || $v === 'yes';
    } catch (\Throwable $e) {
        return false;
    }
}

function xray_static_set_enabled(bool $on): void
{
    try {
        $cnf = \OPNsense\Core\Config::getInstance();
        $cfg = $cnf->object();
        if (!isset($cfg->OPNsense)) {
            $cfg->addChild('OPNsense');
        }
        if (!isset($cfg->OPNsense->xray)) {
            $cfg->OPNsense->addChild('xray');
        }
        if (!isset($cfg->OPNsense->xray->general)) {
            $cfg->OPNsense->xray->addChild('general');
        }
        $g = $cfg->OPNsense->xray->general;
        $val = $on ? '1' : '0';
        if (!isset($g->static_enabled)) {
            $g->addChild('static_enabled', $val);
        } else {
            $g->static_enabled = $val;
        }
        $cnf->save();
        if (method_exists($cnf, 'forceReload')) {
            $cnf->forceReload();
        }
    } catch (\Throwable $e) {
        echo 'WARN: cannot save static_enabled: ' . $e->getMessage() . "\n";
    }
}

/**
 * Write static_protocols.inc: protocol blocks when enabled, comment stub when off.
 */
function xray_static_write_protocols_inc(?bool $enabled = null): void
{
    if ($enabled === null) {
        $enabled = xray_static_enabled();
    }
    if ($enabled) {
        $body = "# generated by os-xray — Enable static route is on\n"
            . "protocol static static_v4 {\n"
            . "    ipv4;\n"
            . "include \"/usr/local/etc/bird/routes_static_v4_apply.inc\";\n"
            . "}\n"
            . "\n"
            . "protocol static static_v6 {\n"
            . "    ipv6;\n"
            . "include \"/usr/local/etc/bird/routes_static_v6_apply.inc\";\n"
            . "}\n";
    } else {
        $body = "# static routes disabled (Enable static route on Static tab)\n";
    }
    xray_static_write_file(XRAY_STATIC_FILES['protocols'], $body);
}

/**
 * Ensure wan_if.inc (IPv4_GATEWAY / IPv6_GATEWAY / WAN*_IF) exists before apply.inc.
 * bird.conf includes wan_if.inc before static_protocols.inc; this refreshes defines first.
 */
function xray_static_ensure_wan_defines(): void
{
    if (function_exists('xray_bird_write_wan_if')) {
        xray_bird_write_wan_if();
        return;
    }
    $peers = __DIR__ . '/xray-bird-peers.php';
    if (is_readable($peers)) {
        require_once $peers;
        if (function_exists('xray_bird_write_wan_if')) {
            xray_bird_write_wan_if();
        }
    }
}

/**
 * Rebuild final *_apply.inc from all intermediate prefix sources.
 *
 * @return array{proxy:int,direct:int,block:int}
 */
function xray_static_build_apply(string $family): array
{
    xray_static_ensure_wan_defines();
    $merged = xray_static_merge_sources($family);
    $path = ($family === 'ipv6') ? XRAY_STATIC_FILES['v6_apply'] : XRAY_STATIC_FILES['v4_apply'];
    xray_static_write_file(
        $path,
        xray_static_render_apply($family, $merged['proxy'], $merged['direct'], $merged['block'])
    );
    return [
        'proxy'  => count($merged['proxy']),
        'direct' => count($merged['direct']),
        'block'  => count($merged['block']),
    ];
}

function xray_static_geodat_id(string $url): string
{
    return xray_static_url_id($url);
}

function xray_static_geodat_cat_dir(): string
{
    return XRAY_STATIC_BIRD_DIR . '/static-geodat-categories';
}

function xray_static_geodat_cat_path(string $url): string
{
    return xray_static_geodat_cat_dir() . '/' . xray_static_geodat_id($url) . '.txt';
}

function xray_static_geodat_assign_for_url(string $url): array
{
    return xray_geosite_parse_assignments(xray_static_read_file(xray_static_geodat_cat_path($url)));
}

/**
 * Ensure default geoip.dat URL(s) exist in static-geodat-urls.json (enabled).
 * Existing rows are kept; only missing URLs are appended.
 *
 * @return array{rows:array,added:int}
 */
function xray_static_seed_default_geodat_urls(bool $verbose = false): array
{
    $rows = xray_static_load_url_rows('geodat');
    $have = [];
    foreach ($rows as $row) {
        $u = strtolower(trim((string)($row['value'] ?? $row['url'] ?? '')));
        if ($u !== '') {
            $have[$u] = true;
        }
    }
    $added = 0;
    foreach (XRAY_STATIC_DEFAULT_GEODAT_URLS as $url) {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        if (isset($have[strtolower($url)])) {
            continue;
        }
        $rows[] = [
            'key'     => 'url',
            'value'   => $url,
            'enabled' => '1',
        ];
        $have[strtolower($url)] = true;
        $added++;
    }
    if ($added > 0) {
        xray_static_save_url_rows('geodat', $rows);
        if ($verbose) {
            echo "static: seeded {$added} default geodat URL(s)\n";
        }
    }
    return ['rows' => $rows, 'added' => $added];
}

/**
 * Seed default category assignments for the default hydraponique geoip.dat URL.
 * Existing assignments are kept; only missing category names are added.
 */
function xray_static_seed_default_geodat_categories(bool $verbose = false): int
{
    $url = XRAY_STATIC_DEFAULT_GEODAT_URLS[0] ?? '';
    if ($url === '' || !function_exists('xray_geosite_render_assignments')) {
        return 0;
    }
    $dir = xray_static_geodat_cat_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        if ($verbose) {
            echo "static: cannot mkdir {$dir}\n";
        }
        return 0;
    }
    $path = xray_static_geodat_cat_path($url);
    $have = is_readable($path)
        ? xray_geosite_parse_assignments(xray_static_read_file($path))
        : [];
    $map = $have;
    $added = 0;
    foreach (XRAY_STATIC_DEFAULT_GEODAT_CATEGORIES as $name => $action) {
        $key = strtolower(trim((string)$name));
        if ($key === '' || isset($map[$key])) {
            continue;
        }
        $map[$key] = $action;
        $added++;
    }
    if ($added === 0 && is_file($path)) {
        return 0;
    }
    $body = xray_geosite_render_assignments($map, $url);
    if (!xray_static_write_file($path, $body)) {
        return 0;
    }
    if ($verbose) {
        echo "static: seeded {$added} default geodat categor" . ($added === 1 ? 'y' : 'ies')
            . " for {$url}\n";
    }
    return $added;
}

function xray_static_seed_defaults(bool $verbose = false): void
{
    xray_static_seed_default_geodat_urls($verbose);
    xray_static_seed_default_geodat_categories($verbose);
}

function xray_static_geodat_has_any_assign(array $urlRows): bool
{
    foreach (xray_static_url_list($urlRows, true) as $url) {
        if (xray_static_geodat_assign_for_url($url) !== []) {
            return true;
        }
    }
    return false;
}

/**
 * Merge prefix caches for enabled list URLs of one kind.
 *
 * @return array{v4:array<string,true>,v6:array<string,true>,used:int,missing:string[]}
 */
function xray_static_prefixes_from_caches(string $kind, array $urlRows): array
{
    $v4 = [];
    $v6 = [];
    $used = 0;
    $missing = [];
    foreach (xray_static_url_list($urlRows, true) as $url) {
        $body = xray_static_cache_read($kind, $url);
        if ($body === '') {
            $missing[] = $url;
            continue;
        }
        $used++;
        $parsed = xray_static_parse_prefix_text($body);
        foreach ($parsed['v4'] as $c => $_) {
            $v4[$c] = true;
        }
        foreach ($parsed['v6'] as $c => $_) {
            $v6[$c] = true;
        }
    }
    return ['v4' => $v4, 'v6' => $v6, 'used' => $used, 'missing' => $missing];
}

/**
 * Build geodat prefix sets from enabled URL caches + category assignments.
 *
 * @return array{proxy4:string[],proxy6:string[],direct4:string[],direct6:string[],block4:string[],block6:string[],used:int,missing:string[]}
 */
function xray_static_geoip_from_caches(array $urlRows): array
{
    $proxy4 = [];
    $proxy6 = [];
    $direct4 = [];
    $direct6 = [];
    $block4 = [];
    $block6 = [];
    $used = 0;
    $missing = [];
    foreach (xray_static_url_list($urlRows, true) as $url) {
        $assign = xray_static_geodat_assign_for_url($url);
        if ($assign === []) {
            continue;
        }
        $body = xray_static_cache_read('geodat', $url);
        if ($body === '') {
            $missing[] = $url;
            continue;
        }
        $used++;
        $cats = xray_geoip_parse_dat($body);
        $col = xray_geoip_collect_prefixes($cats, $assign);
        foreach ($col['proxy4'] as $c) {
            $proxy4[$c] = true;
        }
        foreach ($col['proxy6'] as $c) {
            $proxy6[$c] = true;
        }
        foreach ($col['direct4'] as $c) {
            $direct4[$c] = true;
        }
        foreach ($col['direct6'] as $c) {
            $direct6[$c] = true;
        }
        foreach ($col['block4'] as $c) {
            $block4[$c] = true;
        }
        foreach ($col['block6'] as $c) {
            $block6[$c] = true;
        }
    }
    return [
        'proxy4'  => array_keys($proxy4),
        'proxy6'  => array_keys($proxy6),
        'direct4' => array_keys($direct4),
        'direct6' => array_keys($direct6),
        'block4'  => array_keys($block4),
        'block6'  => array_keys($block6),
        'used'    => $used,
        'missing' => $missing,
    ];
}

/**
 * Download one URL into cache. For list kinds stores normalized prefix text.
 *
 * @return array{ok:bool,message:string,count:int}
 */
function xray_static_download_to_cache(string $kind, string $url): array
{
    $kind = strtolower($kind);
    if (!in_array($kind, ['proxy', 'direct', 'block', 'geodat'], true)) {
        return ['ok' => false, 'message' => 'Invalid kind', 'count' => 0];
    }
    $body = xray_static_fetch_url($url);
    if ($body === '') {
        return ['ok' => false, 'message' => 'Download failed', 'count' => 0];
    }
    if ($kind === 'geodat') {
        $cats = xray_geoip_parse_dat($body);
        if ($cats === []) {
            return ['ok' => false, 'message' => 'No geoip categories found (expect geoip.dat)', 'count' => 0];
        }
        xray_static_cache_write('geodat', $url, $body);
        return ['ok' => true, 'message' => 'OK', 'count' => count($cats)];
    }
    $parsed = xray_static_parse_prefix_text($body);
    $all = array_merge(array_keys($parsed['v4']), array_keys($parsed['v6']));
    if ($all === []) {
        return ['ok' => false, 'message' => 'No IPv4/IPv6 prefixes found', 'count' => 0];
    }
    xray_static_cache_write($kind, $url, xray_static_render_prefix_list($all));
    return ['ok' => true, 'message' => 'OK', 'count' => count($all)];
}

/**
 * Re-fetch all enabled URLs into cache.
 *
 * @return array{fetched:int,failed:string[]}
 */
function xray_static_fetch_enabled_caches(
    array $proxyUrls,
    array $directUrls,
    array $blockUrls,
    array $geodatUrls
): array {
    $fetched = 0;
    $failed = [];
    foreach (
        [
            'proxy'  => $proxyUrls,
            'direct' => $directUrls,
            'block'  => $blockUrls,
            'geodat' => $geodatUrls,
        ] as $kind => $rows
    ) {
        foreach (xray_static_url_list($rows, true) as $url) {
            $r = xray_static_download_to_cache($kind, $url);
            if ($r['ok']) {
                $fetched++;
            } else {
                $failed[] = $url;
            }
        }
    }
    return ['fetched' => $fetched, 'failed' => $failed];
}

function xray_static_bird_reload(): void
{
    exec('/usr/sbin/service bird onestatus 2>&1', $stOut, $stRc);
    $stText = strtolower(implode("\n", $stOut));
    if ($stRc !== 0 || strpos($stText, 'not running') !== false) {
        echo "static: bird not running — skip configure\n";
        return;
    }
    foreach (['/usr/local/sbin/birdc', '/usr/local/bin/birdc'] as $bin) {
        if (is_executable($bin)) {
            exec(escapeshellarg($bin) . ' configure 2>&1', $out, $rc);
            echo $rc === 0
                ? "static: birdc configure OK\n"
                : ("static: birdc configure failed: " . trim(implode(' ', $out)) . "\n");
            return;
        }
    }
    echo "static: birdc not found\n";
}

/**
 * Rebuild intermediate + apply.inc from caches of enabled URLs only (no download).
 *
 * @return array{wrote:bool,proxy4:int,proxy6:int,direct4:int,direct6:int,block4:int,block6:int,used:int,missing:string[],enabled:bool}
 */
function xray_static_refresh(
    array $proxyUrls,
    array $directUrls,
    array $blockUrls,
    array $geodatUrls
): array {
    $proxy = xray_static_prefixes_from_caches('proxy', $proxyUrls);
    $direct = xray_static_prefixes_from_caches('direct', $directUrls);
    $block = xray_static_prefixes_from_caches('block', $blockUrls);
    $missing = array_merge($proxy['missing'], $direct['missing'], $block['missing']);
    $used = $proxy['used'] + $direct['used'] + $block['used'];

    xray_static_write_file(XRAY_STATIC_FILES['v4_proxy'], xray_static_render_prefix_list(array_keys($proxy['v4'])));
    xray_static_write_file(XRAY_STATIC_FILES['v6_proxy'], xray_static_render_prefix_list(array_keys($proxy['v6'])));
    xray_static_write_file(XRAY_STATIC_FILES['v4_direct'], xray_static_render_prefix_list(array_keys($direct['v4'])));
    xray_static_write_file(XRAY_STATIC_FILES['v6_direct'], xray_static_render_prefix_list(array_keys($direct['v6'])));
    xray_static_write_file(XRAY_STATIC_FILES['v4_block'], xray_static_render_prefix_list(array_keys($block['v4'])));
    xray_static_write_file(XRAY_STATIC_FILES['v6_block'], xray_static_render_prefix_list(array_keys($block['v6'])));

    $g4p = [];
    $g6p = [];
    $g4d = [];
    $g6d = [];
    $g4b = [];
    $g6b = [];
    if (xray_static_has_urls($geodatUrls, true) && xray_static_geodat_has_any_assign($geodatUrls)) {
        $geo = xray_static_geoip_from_caches($geodatUrls);
        $used += $geo['used'];
        $missing = array_merge($missing, $geo['missing']);
        $g4p = $geo['proxy4'];
        $g6p = $geo['proxy6'];
        $g4d = $geo['direct4'];
        $g6d = $geo['direct6'];
        $g4b = $geo['block4'];
        $g6b = $geo['block6'];
    }
    xray_static_write_file(XRAY_STATIC_FILES['v4_proxy_geodat'], xray_static_render_prefix_list($g4p));
    xray_static_write_file(XRAY_STATIC_FILES['v6_proxy_geodat'], xray_static_render_prefix_list($g6p));
    xray_static_write_file(XRAY_STATIC_FILES['v4_direct_geodat'], xray_static_render_prefix_list($g4d));
    xray_static_write_file(XRAY_STATIC_FILES['v6_direct_geodat'], xray_static_render_prefix_list($g6d));
    xray_static_write_file(XRAY_STATIC_FILES['v4_block_geodat'], xray_static_render_prefix_list($g4b));
    xray_static_write_file(XRAY_STATIC_FILES['v6_block_geodat'], xray_static_render_prefix_list($g6b));

    $a4 = xray_static_build_apply('ipv4');
    $a6 = xray_static_build_apply('ipv6');
    xray_static_write_protocols_inc();

    return [
        'wrote'    => true,
        'proxy4'   => $a4['proxy'],
        'proxy6'   => $a6['proxy'],
        'direct4'  => $a4['direct'],
        'direct6'  => $a6['direct'],
        'block4'   => $a4['block'],
        'block6'   => $a6['block'],
        'used'     => $used,
        'missing'  => $missing,
        'enabled'  => xray_static_enabled(),
    ];
}

function xray_static_count_prefixes(string $text): int
{
    if (trim($text) === '' || trim($text) === '#') {
        return 0;
    }
    $n = 0;
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        if (xray_static_parse_prefix($line) !== null) {
            $n++;
        }
    }
    return $n;
}

// Allow require from other scripts without running CLI handlers
if (PHP_SAPI !== 'cli' || realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__FILE__)) {
    return;
}

$op = strtolower(trim((string)($argv[1] ?? 'get')));

if ($op === 'seed') {
    xray_static_seed_defaults(true);
    exit(0);
}

if ($op === 'geodatcats') {
    $raw = is_readable(XRAY_STATIC_GEODAT_STAGED)
        ? (string)file_get_contents(XRAY_STATIC_GEODAT_STAGED)
        : '';
    @unlink(XRAY_STATIC_GEODAT_STAGED);
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        echo json_encode(['result' => 'failed', 'message' => 'No staged geodat request'], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    $urlRows = xray_static_rows_from_post($j['geodat_urls'] ?? []);
    if ($urlRows === []) {
        $urlRows = xray_static_load_url_rows('geodat');
    }
    $target = trim((string)($j['geodat_url'] ?? ''));
    if ($target === '' && isset($urlRows[0]['value'])) {
        $target = trim((string)$urlRows[0]['value']);
    }
    $save = array_key_exists('geodat_categories', $j) || array_key_exists('categories', $j);
    $assign = $save
        ? xray_geosite_assignments_from_rows(xray_static_rows_from_post($j['geodat_categories'] ?? $j['categories'] ?? []))
        : ($target !== '' ? xray_static_geodat_assign_for_url($target) : []);

    if ($urlRows !== []) {
        xray_static_save_url_rows('geodat', $urlRows);
    }
    if ($save && $target !== '') {
        if (!is_dir(xray_static_geodat_cat_dir())) {
            @mkdir(xray_static_geodat_cat_dir(), 0755, true);
        }
        xray_static_write_file(xray_static_geodat_cat_path($target), xray_geosite_render_assignments($assign, $target));
    }

    $categories = [];
    $message = '';
    if ($target === '') {
        $message = 'No geodat URL selected';
    } else {
        $body = xray_static_cache_read('geodat', $target);
        if ($body === '') {
            $dl = xray_static_download_to_cache('geodat', $target);
            if (!$dl['ok']) {
                $message = $dl['message'];
            } else {
                $body = xray_static_cache_read('geodat', $target);
            }
        }
        if ($body !== '') {
            $cats = xray_geoip_parse_dat($body);
            // Drop binary dat from memory before building the large JSON response
            $body = '';
            unset($body);
            if ($cats === []) {
                $message = 'No geoip categories found (expect geoip.dat)';
            }
            foreach ($cats as $name => $info) {
                $lk = strtolower((string)$name);
                $categories[] = [
                    'name'   => $name,
                    'count'  => (int)($info['count'] ?? (count($info['v4'] ?? []) + count($info['v6'] ?? []))),
                    // assignments are stored lowercased — look up case-insensitively
                    'action' => $assign[$lk] ?? $assign[$name] ?? '',
                ];
            }
            unset($cats);
        }
    }

    if ($save && $message === '') {
        $message = 'Categories saved';
    }
    if ($save && $target !== '' && $assign !== []) {
        // Confirm from disk so UI sees persisted values even if download/parse failed
        $assign = xray_static_geodat_assign_for_url($target);
        foreach ($categories as &$catRow) {
            $lk = strtolower((string)($catRow['name'] ?? ''));
            $catRow['action'] = $assign[$lk] ?? $assign[$catRow['name'] ?? ''] ?? ($catRow['action'] ?? '');
        }
        unset($catRow);
    }

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    // Ensure stdout is only the JSON object (no prior warnings)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'result'     => ($message !== '' && $categories === [] && !$save) ? 'failed' : 'ok',
        'message'    => $message,
        'geodat_url' => $target,
        'categories' => $categories,
        'saved'      => $save,
    ], $flags) . "\n";
    exit(0);
}

if ($op === 'fetchone') {
    $raw = is_readable(XRAY_STATIC_FETCHONE_STAGED)
        ? (string)file_get_contents(XRAY_STATIC_FETCHONE_STAGED)
        : '';
    @unlink(XRAY_STATIC_FETCHONE_STAGED);
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        echo json_encode(['result' => 'failed', 'message' => 'No staged fetchone request'], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    $kind = strtolower(trim((string)($j['kind'] ?? '')));
    $url = trim((string)($j['url'] ?? ''));
    if (!in_array($kind, ['proxy', 'direct', 'block', 'geodat'], true) || $url === '' || !preg_match('#^https?://#i', $url)) {
        echo json_encode(['result' => 'failed', 'message' => 'kind and http(s) url required'], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    $enabled = array_key_exists('enabled', $j) ? xray_static_row_enabled(['enabled' => $j['enabled']]) : null;
    $rows = xray_static_load_url_rows($kind);
    $rows = xray_static_upsert_url_row($rows, $url, $enabled);
    xray_static_save_url_rows($kind, $rows);
    $dl = xray_static_download_to_cache($kind, $url);
    if (!$dl['ok']) {
        // URL is already persisted — do not report total failure (UI would look like the URL vanished).
        echo json_encode([
            'result'      => 'ok',
            'message'     => 'URL saved, but download failed: ' . $dl['message'],
            'url'         => $url,
            'kind'        => $kind,
            'count'       => 0,
            'download_ok' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }
    // Rebuild bird data if this URL is enabled
    $rowEn = false;
    foreach ($rows as $r) {
        if (strcasecmp(trim((string)($r['value'] ?? '')), $url) === 0) {
            $rowEn = xray_static_row_enabled($r);
            break;
        }
    }
    if ($rowEn) {
        $sum = xray_static_refresh(
            xray_static_load_url_rows('proxy'),
            xray_static_load_url_rows('direct'),
            xray_static_load_url_rows('block'),
            xray_static_load_url_rows('geodat')
        );
        xray_static_bird_reload();
    }
    echo json_encode([
        'result'  => 'ok',
        'message' => 'Saved URL and downloaded ' . $dl['count'] . ' item(s)',
        'url'     => $url,
        'kind'    => $kind,
        'count'   => $dl['count'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

if ($op === 'fetch') {
    // Daily: refresh caches for enabled URLs only, then rebuild
    if (!xray_static_enabled()) {
        echo "OK static disabled — skip fetch\n";
        exit(0);
    }
    $proxyUrls  = xray_static_load_url_rows('proxy');
    $directUrls = xray_static_load_url_rows('direct');
    $blockUrls  = xray_static_load_url_rows('block');
    $geodatUrls = xray_static_load_url_rows('geodat');
    $dl = xray_static_fetch_enabled_caches($proxyUrls, $directUrls, $blockUrls, $geodatUrls);
    $sum = xray_static_refresh($proxyUrls, $directUrls, $blockUrls, $geodatUrls);
    xray_static_bird_reload();
    echo 'OK: static fetch enabled URLs fetched=' . $dl['fetched']
        . ' failed=' . count($dl['failed'])
        . ' proxy4=' . $sum['proxy4'] . ' proxy6=' . $sum['proxy6']
        . ' direct4=' . $sum['direct4'] . ' direct6=' . $sum['direct6']
        . ' block4=' . $sum['block4'] . ' block6=' . $sum['block6']
        . "\n";
    foreach ($dl['failed'] as $u) {
        echo "WARN: download failed: {$u}\n";
    }
    exit(0);
}

if ($op === 'write') {
    if (!is_readable(XRAY_STATIC_STAGED)) {
        echo "OK\n";
        exit(0);
    }
    $raw = (string)file_get_contents(XRAY_STATIC_STAGED);
    @unlink(XRAY_STATIC_STAGED);
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        echo "ERROR: invalid staged static JSON\n";
        exit(1);
    }
    $proxyUrls  = array_key_exists('proxy_urls', $j)
        ? xray_static_rows_from_post($j['proxy_urls'])
        : xray_static_load_url_rows('proxy');
    $directUrls = array_key_exists('direct_urls', $j)
        ? xray_static_rows_from_post($j['direct_urls'])
        : xray_static_load_url_rows('direct');
    $blockUrls  = array_key_exists('block_urls', $j)
        ? xray_static_rows_from_post($j['block_urls'])
        : xray_static_load_url_rows('block');
    $geodatUrls = array_key_exists('geodat_urls', $j)
        ? xray_static_rows_from_post($j['geodat_urls'])
        : xray_static_load_url_rows('geodat');

    xray_static_save_url_rows('proxy', $proxyUrls);
    xray_static_save_url_rows('direct', $directUrls);
    xray_static_save_url_rows('block', $blockUrls);
    xray_static_save_url_rows('geodat', $geodatUrls);

    if (array_key_exists('enabled', $j)) {
        $en = $j['enabled'];
        $on = ($en === true || $en === 1 || $en === '1' || $en === 'on' || $en === 'true' || $en === 'yes');
        xray_static_set_enabled($on);
    }

    $sum = xray_static_refresh($proxyUrls, $directUrls, $blockUrls, $geodatUrls);
    xray_static_bird_reload();

    echo 'OK: static prefixes'
        . ' enabled=' . ($sum['enabled'] ? '1' : '0')
        . ' proxy4=' . $sum['proxy4'] . ' proxy6=' . $sum['proxy6']
        . ' direct4=' . $sum['direct4'] . ' direct6=' . $sum['direct6']
        . ' block4=' . $sum['block4'] . ' block6=' . $sum['block6']
        . ' used=' . $sum['used']
        . ($sum['missing'] !== [] ? (' missing=' . count($sum['missing'])) : '')
        . "\n";
    foreach ($sum['missing'] as $u) {
        echo "WARN: no cached data for enabled URL (use ✔): {$u}\n";
    }
    exit(0);
}

if ($op === 'extra') {
    $raw = is_readable(XRAY_STATIC_EXTRA_STAGED)
        ? (string)file_get_contents(XRAY_STATIC_EXTRA_STAGED)
        : '';
    @unlink(XRAY_STATIC_EXTRA_STAGED);
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        echo json_encode(['result' => 'failed', 'message' => 'No staged extra request'], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    // birdc и предупреждения не должны ломать JSON для UI
    ob_start();
    $res = xray_static_extra_mutate(
        (string)($j['action'] ?? ''),
        (string)($j['kind'] ?? ''),
        (string)($j['prefix'] ?? '')
    );
    if ($res['changed']) {
        xray_static_refresh(
            xray_static_load_url_rows('proxy'),
            xray_static_load_url_rows('direct'),
            xray_static_load_url_rows('block'),
            xray_static_load_url_rows('geodat')
        );
        xray_static_bird_reload();
    }
    $noise = trim((string)ob_get_clean());
    $message = $res['message'];
    if ($noise !== '') {
        $message = trim($message . "\n" . $noise);
    }
    echo json_encode([
        'result'  => $res['ok'] ? 'ok' : 'failed',
        'message' => $message,
        'changed' => $res['changed'],
        'prefix'  => $res['prefix'],
        'kind'    => $res['kind'],
        'extra'   => xray_static_extra_lists(),
        'counts'  => xray_static_apply_counts(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($res['ok'] ? 0 : 1);
}

if ($op === 'extrawrite') {
    $raw = is_readable(XRAY_STATIC_EXTRA_STAGED)
        ? (string)file_get_contents(XRAY_STATIC_EXTRA_STAGED)
        : '';
    @unlink(XRAY_STATIC_EXTRA_STAGED);
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        echo json_encode(['result' => 'failed', 'message' => 'No staged extra write'], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    $decodeList = static function ($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    };
    ob_start();
    $res = xray_static_extra_replace([
        'proxy'  => $decodeList($j['proxy'] ?? []),
        'direct' => $decodeList($j['direct'] ?? []),
        'block'  => $decodeList($j['block'] ?? []),
    ]);
    if ($res['ok']) {
        xray_static_refresh(
            xray_static_load_url_rows('proxy'),
            xray_static_load_url_rows('direct'),
            xray_static_load_url_rows('block'),
            xray_static_load_url_rows('geodat')
        );
        xray_static_bird_reload();
    }
    $noise = trim((string)ob_get_clean());
    $message = $res['message'];
    if ($noise !== '') {
        $message = trim($message . "\n" . $noise);
    }
    echo json_encode([
        'result'  => $res['ok'] ? 'ok' : 'failed',
        'message' => $message,
        'extra'   => xray_static_extra_lists(),
        'counts'  => xray_static_apply_counts(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($res['ok'] ? 0 : 1);
}

// get
xray_static_seed_defaults();
$proxyUrls  = xray_static_load_url_rows('proxy');
$directUrls = xray_static_load_url_rows('direct');
$blockUrls  = xray_static_load_url_rows('block');
$geodatUrls = xray_static_load_url_rows('geodat');

echo json_encode([
    'result'      => 'ok',
    'enabled'     => xray_static_enabled() ? '1' : '0',
    'proxy_urls'  => $proxyUrls,
    'direct_urls' => $directUrls,
    'block_urls'  => $blockUrls,
    'geodat_urls' => $geodatUrls,
    'extra'       => xray_static_extra_lists(),
    'counts'      => xray_static_apply_counts(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
