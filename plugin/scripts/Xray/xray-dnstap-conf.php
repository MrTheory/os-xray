#!/usr/local/bin/php
<?php
/**
 * Read/write os-dnstap-bgp files as key/value for the Xray DNStap tab.
 *
 *   xray-dnstap-conf.php get
 *   xray-dnstap-conf.php write
 */

$xray_config_inc = '/usr/local/etc/inc/config.inc';
if (is_file($xray_config_inc)) {
    require_once $xray_config_inc;
} elseif (is_file('config.inc')) {
    require_once 'config.inc';
}
require_once __DIR__ . '/xray-dnstap-unbound.php';
require_once __DIR__ . '/xray-dnstap-geosite.php';

const XRAY_DNSTAP_STAGED = '/tmp/xray_dnstap_write.json';
const XRAY_DNSTAP_GEODAT_STAGED = '/tmp/xray_dnstap_geodat.json';
const XRAY_DNSTAP_FETCHONE_STAGED = '/tmp/xray_dnstap_fetchone.json';
const XRAY_DNSTAP_CACHE_DIR = '/usr/local/etc/dnstap-bgp/url-cache';
const XRAY_DNSTAP_MAX = 524288;
const XRAY_DNSTAP_DOMAINS_MAX = 33554432;
const XRAY_DNSTAP_CONF_HIDDEN = [
    'domains',
    'proxy_domains',
    'direct_domains',
    'block_domains',
    'blocked_domains',
    'unblocked_domains',
    'unproxy_domains',
    'dnstap.block_domains',
    'dnstap.unproxy_domains',
    'bgp.block_domains',
    'cache',
    'dnstap.listen',
    'dnstap.perm',
    'bgp.as',
    'bgp.routerID',
    'bgp.nextHop',
];
const XRAY_DNSTAP_FILES = [
    'conf'           => '/usr/local/etc/dnstap-bgp/dnstap-bgp.conf',
    'proxy'          => '/usr/local/etc/dnstap-bgp/proxy.txt',
    'direct'         => '/usr/local/etc/dnstap-bgp/direct.txt',
    'block'          => '/usr/local/etc/dnstap-bgp/block.txt',
    'proxy_extra'    => '/usr/local/etc/dnstap-bgp/proxy-extra.txt',
    'direct_extra'   => '/usr/local/etc/dnstap-bgp/direct-extra.txt',
    'block_extra'    => '/usr/local/etc/dnstap-bgp/block-extra.txt',
    'proxy_urls'     => '/usr/local/etc/dnstap-bgp/proxy-urls.txt',
    'direct_urls'    => '/usr/local/etc/dnstap-bgp/direct-urls.txt',
    'block_urls'     => '/usr/local/etc/dnstap-bgp/block-urls.txt',
    'geodat_urls'    => '/usr/local/etc/dnstap-bgp/geodat-urls.txt',
    'rc'             => '/usr/local/etc/rc.conf.d/dnstap_bgp',
];
const XRAY_DNSTAP_LEGACY = [
    'domains'      => '/usr/local/etc/dnstap-bgp/domains.txt',
    'unblocked'    => '/usr/local/etc/dnstap-bgp/unblocked.txt',
    'extra'        => '/usr/local/etc/dnstap-bgp/domains-extra.txt',
    'blocked_extra'=> '/usr/local/etc/dnstap-bgp/blocked-extra.txt',
    'unblocked_extra' => '/usr/local/etc/dnstap-bgp/unblocked-extra.txt',
    'urls'         => '/usr/local/etc/dnstap-bgp/domain-urls.txt',
    'blocked_urls' => '/usr/local/etc/dnstap-bgp/blocked-urls.txt',
    'unblocked_urls' => '/usr/local/etc/dnstap-bgp/unblocked-urls.txt',
];

function xray_dnstap_running(): bool
{
    if (!is_executable('/usr/local/sbin/dnstap-bgp')
        && !is_file('/usr/local/etc/rc.d/dnstap_bgp')
        && !is_file('/etc/rc.d/dnstap_bgp')) {
        return false;
    }
    exec('/usr/sbin/service dnstap_bgp onestatus 2>&1', $out, $rc);
    $text = strtolower(implode("\n", $out));
    if (strpos($text, 'not running') !== false) {
        return false;
    }
    return $rc === 0;
}

function xray_dnstap_read_file(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }
    $raw = (string)file_get_contents($path);
    if (strlen($raw) > XRAY_DNSTAP_MAX) {
        return substr($raw, 0, XRAY_DNSTAP_MAX);
    }
    return $raw;
}

function xray_dnstap_rc_is_enabled(string $path): bool
{
    return (bool)preg_match('/^dnstap_bgp_enable\s*=\s*"?YES"?/mi', xray_dnstap_read_file($path));
}

function xray_dnstap_reload(): bool
{
    if (!xray_dnstap_running()) {
        return false;
    }
    exec('/usr/sbin/service dnstap_bgp reload 2>&1', $out, $rc);
    if ($rc === 0) {
        return true;
    }
    return xray_dnstap_sighup();
}

function xray_dnstap_sighup(): bool
{
    $pid = 0;
    foreach (['/var/run/dnstap_bgp.pid', '/var/run/dnstap-bgp.pid'] as $pidfile) {
        if (!is_readable($pidfile)) {
            continue;
        }
        $pid = (int)trim((string)file_get_contents($pidfile));
        if ($pid > 0) {
            break;
        }
    }
    if ($pid <= 0) {
        exec("pgrep -x dnstap-bgp 2>/dev/null", $out, $rc);
        $pid = (int)trim((string)($out[0] ?? '0'));
    }
    if ($pid <= 0) {
        return false;
    }
    exec('kill -HUP ' . $pid . ' 2>&1', $kout, $krc);
    return $krc === 0;
}

function xray_dnstap_write_file(string $path, string $body): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (file_put_contents($path, $body) === false) {
        return false;
    }
    @chmod($path, 0644);
    return true;
}

function xray_dnstap_parse_toml_scalar(string $v)
{
    $v = trim($v);
    $v = rtrim($v, ',');
    if ($v === 'true') {
        return 'true';
    }
    if ($v === 'false') {
        return 'false';
    }
    if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
        $q = $v[0];
        if (preg_match('/^' . preg_quote($q, '/') . '(.*)' . preg_quote($q, '/') . '/', $v, $m)) {
            return $m[1];
        }
    }
    if (preg_match('/^-?\d+$/', $v)) {
        return $v;
    }
    return $v;
}

function xray_dnstap_parse_toml(string $text): array
{
    $out     = [];
    $section = '';
    $arrKey  = null;
    $arr     = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }
        if ($arrKey !== null) {
            if (strpos($trim, ']') === 0) {
                $out[] = ['key' => $arrKey, 'value' => implode(', ', $arr)];
                $arrKey = null;
                $arr    = [];
                continue;
            }
            if (preg_match('/^"([^"]*)"/', $trim, $m) || preg_match("/^'([^']*)'/", $trim, $m)) {
                $arr[] = $m[1];
            } elseif (preg_match('/^([^,#\]]+)/', $trim, $m)) {
                $item = trim($m[1], " \t\",");
                if ($item !== '') {
                    $arr[] = $item;
                }
            }
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]/', $trim, $m)) {
            $section = trim($m[1]);
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $trim, $m)) {
            $k = $section !== '' ? $section . '.' . $m[1] : $m[1];
            $v = trim($m[2]);
            if ($v === '[' || preg_match('/^\[\s*$/', $v)) {
                $arrKey = $k;
                $arr    = [];
                continue;
            }
            if (preg_match('/^\[(.*)\]$/', $v, $am)) {
                $items = [];
                if (preg_match_all('/"([^"]*)"/', $am[1], $qm)) {
                    $items = $qm[1];
                }
                $out[] = ['key' => $k, 'value' => implode(', ', $items)];
                continue;
            }
            $out[] = ['key' => $k, 'value' => (string)xray_dnstap_parse_toml_scalar($v)];
        }
    }
    if ($arrKey !== null) {
        $out[] = ['key' => $arrKey, 'value' => implode(', ', $arr)];
    }
    return $out;
}

function xray_dnstap_is_array_key(string $key): bool
{
    $name = $key;
    $dot = strrpos($key, '.');
    if ($dot !== false) {
        $name = substr($key, $dot + 1);
    }
    return $name === 'peers'
        || $name === 'proxy_communities'
        || $name === 'direct_communities'
        || $name === 'blocked_communities'
        || $name === 'unblocked_communities'
        || substr($name, -12) === '_communities';
}

function xray_dnstap_toml_scalar(string $key, string $v): string
{
    $v = trim($v);
    if ($v === 'true' || $v === 'false') {
        return $v;
    }
    if ($key === 'bgp.as' || $key === 'as' || preg_match('/^-?[1-9]\d*$/', $v) || $v === '0') {
        if (preg_match('/^-?\d+$/', $v) && ($v === '0' || $v[0] !== '0')) {
            return $v;
        }
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

function xray_dnstap_conf_is_hidden(string $key): bool
{
    return in_array($key, XRAY_DNSTAP_CONF_HIDDEN, true);
}

function xray_dnstap_conf_managed_rows(): array
{
    $as = function_exists('xray_dnstap_bird_local_as') ? xray_dnstap_bird_local_as() : 65103;
    $sock = defined('XRAY_DNSTAP_SOCK') ? XRAY_DNSTAP_SOCK : '/var/unbound/var/run/dnstap-bgp/dnstap.sock';
    $perm = defined('XRAY_DNSTAP_PERM') ? XRAY_DNSTAP_PERM : '0666';
    return [
        ['key' => 'domains', 'value' => XRAY_DNSTAP_FILES['proxy']],
        ['key' => 'proxy_domains', 'value' => XRAY_DNSTAP_FILES['proxy']],
        ['key' => 'direct_domains', 'value' => XRAY_DNSTAP_FILES['direct']],
        ['key' => 'block_domains', 'value' => XRAY_DNSTAP_FILES['block']],
        ['key' => 'cache', 'value' => '/var/db/dnstap-bgp/cache.db'],
        ['key' => 'dnstap.listen', 'value' => $sock],
        ['key' => 'dnstap.perm', 'value' => $perm],
        ['key' => 'bgp.as', 'value' => (string)$as],
    ];
}

function xray_dnstap_default_communities(): array
{
    $as = function_exists('xray_dnstap_bird_local_as') ? xray_dnstap_bird_local_as() : 65103;
    return [
        'bgp.proxy_communities'  => $as . ':777',
        'bgp.direct_communities' => $as . ':555',
        'bgp.block_communities'  => $as . ':888',
    ];
}

function xray_dnstap_ensure_ipv6_row(array $rows): array
{
    foreach ($rows as $row) {
        if (trim((string)($row['key'] ?? '')) === 'ipv6') {
            return $rows;
        }
    }
    $rows[] = ['key' => 'ipv6', 'value' => 'true'];
    return $rows;
}

function xray_dnstap_ensure_community_rows(array $rows): array
{
    $defs = xray_dnstap_default_communities();
    $have = [];
    foreach ($rows as &$row) {
        $k = trim((string)($row['key'] ?? ''));
        if (isset($defs[$k])) {
            $row['value'] = $defs[$k];
            $have[$k] = true;
        }
    }
    unset($row);
    foreach ($defs as $k => $v) {
        if (empty($have[$k])) {
            $rows[] = ['key' => $k, 'value' => $v];
        }
    }
    return $rows;
}

function xray_dnstap_conf_merge_managed(array $guiRows): array
{
    $out = [];
    $source = '';
    foreach ($guiRows as $row) {
        $k = trim((string)($row['key'] ?? ''));
        $v = (string)($row['value'] ?? '');
        if ($k === '' || xray_dnstap_conf_is_hidden($k)) {
            continue;
        }
        if ($k === 'bgp.blocked_communities' || $k === 'blocked_communities') {
            $k = 'bgp.proxy_communities';
        }
        if ($k === 'bgp.unblocked_communities' || $k === 'unblocked_communities'
            || $k === 'bgp.unproxy_communities' || $k === 'unproxy_communities') {
            $k = 'bgp.direct_communities';
        }
        if ($k === 'ipv6') {
            $on = in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
            $v = $on ? 'true' : 'false';
        }
        if ($k === 'bgp.sourceIP') {
            $source = function_exists('xray_dnstap_strip_cidr')
                ? xray_dnstap_strip_cidr($v)
                : trim($v);
        }
        $out[] = ['key' => $k, 'value' => $v];
    }
    if ($source !== '') {
        $out[] = ['key' => 'bgp.routerID', 'value' => $source];
        $out[] = ['key' => 'bgp.nextHop', 'value' => $source];
    }
    $out = xray_dnstap_ensure_ipv6_row($out);
    $out = xray_dnstap_ensure_community_rows($out);
    foreach (xray_dnstap_conf_managed_rows() as $row) {
        $out[] = $row;
    }
    return $out;
}

function xray_dnstap_conf_for_ui(array $rows): array
{
    $rename = [
        'bgp.blocked_communities'   => 'bgp.proxy_communities',
        'bgp.unblocked_communities' => 'bgp.direct_communities',
        'bgp.unproxy_communities'   => 'bgp.direct_communities',
        'blocked_communities'       => 'bgp.proxy_communities',
        'unblocked_communities'     => 'bgp.direct_communities',
        'unproxy_communities'       => 'bgp.direct_communities',
        'unproxy_domains'           => 'direct_domains',
        'dnstap.unproxy_domains'    => 'direct_domains',
        'dnstap.block_domains'      => 'block_domains',
        'bgp.block_domains'         => 'block_domains',
    ];
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $k = trim((string)($row['key'] ?? ''));
        if (isset($rename[$k])) {
            $k = $rename[$k];
            $row['key'] = $k;
        }
        if ($k === '' || xray_dnstap_conf_is_hidden($k) || !empty($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $row;
    }
    return xray_dnstap_ensure_community_rows(xray_dnstap_ensure_ipv6_row($out));
}

function xray_dnstap_render_toml(array $rows): string
{
    $root = [];
    $secs = [];
    foreach ($rows as $row) {
        $k = trim((string)($row['key'] ?? ''));
        $v = (string)($row['value'] ?? '');
        if ($k === '') {
            continue;
        }
        $dot = strpos($k, '.');
        if ($dot === false) {
            $root[] = [$k, $v];
        } else {
            $sec = substr($k, 0, $dot);
            $name = substr($k, $dot + 1);
            if (!isset($secs[$sec])) {
                $secs[$sec] = [];
            }
            $secs[$sec][] = [$name, $v, $k];
        }
    }
    $lines = [
        '# generated by os-xray DNStap tab',
        '',
    ];
    foreach ($root as [$k, $v]) {
        $lines[] = xray_dnstap_render_toml_assign($k, $v, $k);
    }
    foreach ($secs as $sec => $pairs) {
        $lines[] = '';
        $lines[] = '[' . $sec . ']';
        foreach ($pairs as [$name, $v, $full]) {
            $lines[] = xray_dnstap_render_toml_assign($name, $v, $full);
        }
    }
    return implode("\n", $lines) . "\n";
}

function xray_dnstap_render_toml_assign(string $name, string $v, string $fullKey): string
{
    if (xray_dnstap_is_array_key($fullKey)) {
        $items = [];
        foreach (preg_split('/\s*,\s*/', $v) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $items[] = '  "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $item) . '",';
        }
        return $name . " = [\n" . implode("\n", $items) . "\n]";
    }
    return $name . ' = ' . xray_dnstap_toml_scalar($fullKey, $v);
}

function xray_dnstap_parse_rc(string $text): array
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*"(.*)"\s*$/', $trim, $m)) {
            $out[] = ['key' => $m[1], 'value' => $m[2]];
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(\S+)\s*$/', $trim, $m)) {
            $out[] = ['key' => $m[1], 'value' => $m[2]];
        }
    }
    return $out;
}

function xray_dnstap_render_rc(array $rows): string
{
    $lines = [
        '# /usr/local/etc/rc.conf.d/dnstap_bgp',
        '# generated by os-xray DNStap tab',
        '',
    ];
    foreach ($rows as $row) {
        $k = trim((string)($row['key'] ?? ''));
        $v = (string)($row['value'] ?? '');
        if ($k === '' || !preg_match('/^[A-Za-z0-9_]+$/', $k)) {
            continue;
        }
        $lines[] = $k . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
    return implode("\n", $lines) . "\n";
}

function xray_dnstap_parse_domains(string $text): array
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }
        $out[] = ['key' => 'domain', 'value' => $trim];
    }
    return $out;
}

function xray_dnstap_render_domains(array $rows): string
{
    $lines = [
        '# One lowercase FQDN per line. IDN/punycode is not supported.',
        '',
    ];
    $seen = [];
    foreach ($rows as $row) {
        $v = xray_dnstap_normalize_domain((string)($row['value'] ?? ''));
        if ($v === '' || isset($seen[$v])) {
            continue;
        }
        $seen[$v] = true;
        $lines[] = $v;
    }
    return implode("\n", $lines) . "\n";
}

function xray_dnstap_normalize_domain(string $raw): string
{
    $v = trim($raw);
    if (strncmp($v, "\xEF\xBB\xBF", 3) === 0) {
        $v = substr($v, 3);
    }
    $v = preg_replace('/#.*$/', '', $v);
    $v = trim($v, " \t\n\r\0\x0B.");
    if ($v === '') {
        return '';
    }
    $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    $v = preg_replace('/^https?:\/\//', '', $v);
    $v = preg_replace('/\/.*$/', '', $v);
    $v = rtrim($v, '.');
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $v)) {
        return '';
    }
    return $v;
}

function xray_dnstap_parse_domain_text(string $text): array
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $d = xray_dnstap_normalize_domain($line);
        if ($d !== '') {
            $out[$d] = true;
        }
    }
    return $out;
}

function xray_dnstap_row_enabled($row): bool
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

function xray_dnstap_parse_urls(string $text): array
{
    $trim = ltrim($text);
    if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
        $j = json_decode($text, true);
        if (is_array($j)) {
            return xray_dnstap_rows_from_post($j);
        }
    }
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('#^https?://#i', $line)) {
            continue;
        }
        $out[] = ['key' => 'url', 'value' => $line, 'enabled' => '1'];
    }
    return $out;
}

function xray_dnstap_render_urls(array $rows, string $kind = 'proxy'): string
{
    unset($kind);
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $v = trim((string)($row['value'] ?? $row['url'] ?? ''));
        if ($v === '' || !preg_match('#^https?://#i', $v) || isset($seen[$v])) {
            continue;
        }
        $seen[$v] = true;
        $out[] = [
            'key'     => 'url',
            'url'     => $v,
            'value'   => $v,
            'enabled' => xray_dnstap_row_enabled($row) ? '1' : '0',
        ];
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

function xray_dnstap_url_list(array $rows, bool $enabledOnly = false): array
{
    $urls = [];
    $seen = [];
    foreach ($rows as $row) {
        if ($enabledOnly && !xray_dnstap_row_enabled($row)) {
            continue;
        }
        $v = trim((string)($row['value'] ?? $row['url'] ?? ''));
        if ($v === '' || !preg_match('#^https?://#i', $v) || isset($seen[$v])) {
            continue;
        }
        $seen[$v] = true;
        $urls[] = $v;
    }
    return $urls;
}

function xray_dnstap_url_id(string $url): string
{
    return sha1(strtolower(rtrim(trim($url), '/')));
}

function xray_dnstap_cache_path(string $kind, string $url): string
{
    $kind = preg_replace('/[^a-z]/', '', strtolower($kind)) ?: 'proxy';
    $ext = ($kind === 'geodat') ? '.dat' : '.txt';
    return XRAY_DNSTAP_CACHE_DIR . '/' . $kind . '/' . xray_dnstap_url_id($url) . $ext;
}

function xray_dnstap_cache_write(string $kind, string $url, string $body): bool
{
    return xray_dnstap_write_file(xray_dnstap_cache_path($kind, $url), $body);
}

function xray_dnstap_cache_read(string $kind, string $url): string
{
    return xray_dnstap_read_file(xray_dnstap_cache_path($kind, $url));
}

function xray_dnstap_upsert_url_row(array $rows, string $url, ?bool $enabled = null): array
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

/**
 * @return array{ok:bool,message:string,count:int}
 */
function xray_dnstap_download_to_cache(string $kind, string $url): array
{
    $kind = strtolower($kind);
    if (!in_array($kind, ['proxy', 'direct', 'block', 'geodat'], true)) {
        return ['ok' => false, 'message' => 'Invalid kind', 'count' => 0];
    }
    $body = xray_dnstap_fetch_url($url);
    if ($body === '') {
        return ['ok' => false, 'message' => 'Download failed', 'count' => 0];
    }
    if ($kind === 'geodat') {
        $cats = function_exists('xray_geosite_parse_dat') ? xray_geosite_parse_dat($body) : [];
        if ($cats === []) {
            return ['ok' => false, 'message' => 'No geosite categories found (expect geosite.dat)', 'count' => 0];
        }
        xray_dnstap_cache_write('geodat', $url, $body);
        return ['ok' => true, 'message' => 'OK', 'count' => count($cats)];
    }
    $set = xray_dnstap_parse_domain_text($body);
    if ($set === []) {
        return ['ok' => false, 'message' => 'No domains found', 'count' => 0];
    }
    $list = array_keys($set);
    sort($list, SORT_STRING);
    $lines = ['# Cached ' . $kind . ' domains from ' . $url, ''];
    foreach ($list as $d) {
        $lines[] = $d;
    }
    xray_dnstap_cache_write($kind, $url, implode("\n", $lines) . "\n");
    return ['ok' => true, 'message' => 'OK', 'count' => count($list)];
}

function xray_dnstap_body_from_cache_or_fetch(string $kind, string $url, array &$fetched, array &$failed): string
{
    $body = xray_dnstap_cache_read($kind, $url);
    if ($body !== '') {
        return $body;
    }
    $dl = xray_dnstap_download_to_cache($kind, $url);
    if (!$dl['ok']) {
        $failed[] = $url;
        return '';
    }
    $fetched[] = $url;
    return xray_dnstap_cache_read($kind, $url);
}

function xray_dnstap_fetch_url(string $url): string
{
    $tmp = tempnam('/tmp', 'dnstap_dl_');
    if ($tmp === false) {
        return '';
    }
    $cmd = '/usr/bin/fetch -q -o ' . escapeshellarg($tmp) . ' -T 45 ' . escapeshellarg($url);
    exec($cmd . ' 2>&1', $out, $rc);
    if ($rc !== 0 || !is_readable($tmp)) {
        @unlink($tmp);
        $cmd = '/usr/local/bin/curl -fsSL --max-time 45 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
        exec($cmd . ' 2>&1', $out2, $rc2);
        if ($rc2 !== 0 || !is_readable($tmp)) {
            @unlink($tmp);
            return '';
        }
    }
    $size = (int)filesize($tmp);
    if ($size <= 0 || $size > XRAY_DNSTAP_DOMAINS_MAX) {
        @unlink($tmp);
        return '';
    }
    $body = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $body;
}

function xray_dnstap_count_domains(string $text): int
{
    $n = 0;
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        if (xray_dnstap_normalize_domain($line) !== '') {
            $n++;
        }
    }
    return $n;
}

function xray_dnstap_read_first(array $paths): string
{
    foreach ($paths as $path) {
        $text = xray_dnstap_read_file($path);
        if (trim($text) !== '') {
            return $text;
        }
    }
    return '';
}

/**
 * True if $domain equals or is a subdomain of any name in $higherSet (block/proxy parents).
 */
function xray_dnstap_covered_by(string $domain, array $higherSet): bool
{
    if ($domain === '' || $higherSet === []) {
        return false;
    }
    if (isset($higherSet[$domain])) {
        return true;
    }
    foreach ($higherSet as $parent => $_) {
        $parent = (string)$parent;
        if ($parent === '') {
            continue;
        }
        $suffix = '.' . $parent;
        $len = strlen($suffix);
        if (strlen($domain) > $len && substr($domain, -$len) === $suffix) {
            return true;
        }
    }
    return false;
}

function xray_dnstap_merge_domains(array $extraRows, array $urlRows, string $kind = 'proxy', array $exclude = [], array $more = []): array
{
    $set = [];
    foreach ($extraRows as $row) {
        $d = xray_dnstap_normalize_domain((string)($row['value'] ?? ''));
        if ($d !== '' && !xray_dnstap_covered_by($d, $exclude)) {
            $set[$d] = true;
        }
    }
    $fetchedList = [];
    $failed  = [];
    // Only enabled URLs; prefer cache (✔), download once if cache missing
    foreach (xray_dnstap_url_list($urlRows, true) as $url) {
        $body = xray_dnstap_body_from_cache_or_fetch($kind, $url, $fetchedList, $failed);
        if ($body === '') {
            continue;
        }
        foreach (xray_dnstap_parse_domain_text($body) as $d => $_) {
            if (!xray_dnstap_covered_by($d, $exclude)) {
                $set[$d] = true;
            }
        }
    }
    foreach ($more as $raw) {
        $d = xray_dnstap_normalize_domain((string)$raw);
        if ($d !== '' && !xray_dnstap_covered_by($d, $exclude)) {
            $set[$d] = true;
        }
    }
    $list = array_keys($set);
    sort($list, SORT_STRING);
    $lines = [
        '# Summarized ' . $kind . ' domains (unique). Generated by os-xray.',
        '',
    ];
    foreach ($list as $d) {
        $lines[] = $d;
    }
    return [
        'body'    => implode("\n", $lines) . "\n",
        'count'   => count($list),
        'fetched' => count($fetchedList),
        'failed'  => $failed,
    ];
}

function xray_dnstap_has_urls(array $urlRows, bool $enabledOnly = false): bool
{
    return xray_dnstap_url_list($urlRows, $enabledOnly) !== [];
}

function xray_dnstap_geosite_load(array $urlRows, bool $enabledOnly = true): array
{
    $cats = [];
    $fetched = 0;
    $failed = [];
    $fetchedList = [];
    foreach (xray_dnstap_url_list($urlRows, $enabledOnly) as $url) {
        $body = xray_dnstap_body_from_cache_or_fetch('geodat', $url, $fetchedList, $failed);
        if ($body === '') {
            continue;
        }
        $parsed = xray_geosite_parse_dat($body);
        if ($parsed === []) {
            $failed[] = $url;
            continue;
        }
        $fetched++;
        foreach ($parsed as $name => $set) {
            if (!isset($cats[$name])) {
                $cats[$name] = [];
            }
            foreach ($set as $d => $_) {
                $cats[$name][$d] = true;
            }
        }
    }
    ksort($cats, SORT_STRING);
    return ['cats' => $cats, 'fetched' => $fetched, 'failed' => array_values(array_unique($failed))];
}

function xray_dnstap_geodat_assign_for_url(string $url): array
{
    return xray_geosite_parse_assignments(xray_dnstap_read_file(xray_dnstap_geodat_cat_path($url)));
}

function xray_dnstap_geodat_has_any_assign(array $urlRows, bool $enabledOnly = true): bool
{
    foreach (xray_dnstap_url_list($urlRows, $enabledOnly) as $url) {
        if (xray_dnstap_geodat_assign_for_url($url) !== []) {
            return true;
        }
    }
    return false;
}

function xray_dnstap_geosite_sets(array $urlRows): array
{
    $proxy = [];
    $direct = [];
    $block = [];
    $fetched = 0;
    $failed = [];
    foreach (xray_dnstap_url_list($urlRows, true) as $url) {
        $assign = xray_dnstap_geodat_assign_for_url($url);
        if ($assign === []) {
            continue;
        }
        $load = xray_dnstap_geosite_load([['key' => 'url', 'value' => $url, 'enabled' => '1']], true);
        $fetched += $load['fetched'];
        $failed = array_merge($failed, $load['failed']);
        $collected = xray_geosite_collect_domains($load['cats'], $assign);
        foreach ($collected['proxy'] as $d) {
            $proxy[$d] = true;
        }
        foreach ($collected['direct'] as $d) {
            $direct[$d] = true;
        }
        foreach ($collected['block'] as $d) {
            $block[$d] = true;
        }
    }
    return [
        'proxy'   => array_keys($proxy),
        'direct'  => array_keys($direct),
        'block'   => array_keys($block),
        'fetched' => $fetched,
        'failed'  => $failed,
    ];
}

function xray_dnstap_geosite_ui_rows(array $cats, array $assign): array
{
    $rows = [];
    foreach ($cats as $name => $set) {
        $rows[] = [
            'name'   => $name,
            'count'  => count($set),
            'action' => $assign[$name] ?? '',
        ];
    }
    return $rows;
}

function xray_dnstap_commit_summaries(
    array $proxyExtra,
    array $proxyUrls,
    array $directExtra,
    array $directUrls,
    array $geoProxy,
    array $geoDirect,
    array $blockExtra = [],
    array $blockUrls = [],
    array $geoBlock = []
): array {
    $mergedB = xray_dnstap_merge_domains($blockExtra, $blockUrls, 'block', [], $geoBlock);
    if (!xray_dnstap_write_file(XRAY_DNSTAP_FILES['block'], $mergedB['body'])) {
        echo "ERROR: cannot write " . XRAY_DNSTAP_FILES['block'] . "\n";
    }
    $exclude = xray_dnstap_parse_domain_text($mergedB['body']);
    $merged = xray_dnstap_merge_domains($proxyExtra, $proxyUrls, 'proxy', $exclude, $geoProxy);
    if (!xray_dnstap_write_file(XRAY_DNSTAP_FILES['proxy'], $merged['body'])) {
        echo "ERROR: cannot write " . XRAY_DNSTAP_FILES['proxy'] . "\n";
    }
    $exclude = $exclude + xray_dnstap_parse_domain_text($merged['body']);
    $mergedU = xray_dnstap_merge_domains($directExtra, $directUrls, 'direct', $exclude, $geoDirect);
    if (!xray_dnstap_write_file(XRAY_DNSTAP_FILES['direct'], $mergedU['body'])) {
        echo "ERROR: cannot write " . XRAY_DNSTAP_FILES['direct'] . "\n";
    }
    return [
        'block'   => $mergedB,
        'proxy'   => $merged,
        'direct'  => $mergedU,
        'fetched' => $mergedB['fetched'] + $merged['fetched'] + $mergedU['fetched'],
        'failed'  => array_merge($mergedB['failed'], $merged['failed'], $mergedU['failed']),
    ];
}

function xray_dnstap_refresh_from_urls(): array
{
    xray_dnstap_ensure_domain_files();
    $proxyExtra = xray_dnstap_parse_domains(xray_dnstap_read_first([
        XRAY_DNSTAP_FILES['proxy_extra'],
        XRAY_DNSTAP_LEGACY['blocked_extra'],
        XRAY_DNSTAP_LEGACY['extra'],
    ]));
    $proxyUrls = xray_dnstap_load_url_rows('proxy');
    $directExtra = xray_dnstap_parse_domains(xray_dnstap_read_first([
        XRAY_DNSTAP_FILES['direct_extra'],
        XRAY_DNSTAP_LEGACY['unblocked_extra'],
    ]));
    $directUrls = xray_dnstap_load_url_rows('direct');
    $blockExtra = xray_dnstap_parse_domains(xray_dnstap_read_file(XRAY_DNSTAP_FILES['block_extra']));
    $blockUrls = xray_dnstap_load_url_rows('block');
    $geodatUrls = xray_dnstap_load_url_rows('geodat');

    $hasGeo = xray_dnstap_has_urls($geodatUrls, true);
    $hasUrls = xray_dnstap_has_urls($proxyUrls, true) || xray_dnstap_has_urls($directUrls, true)
        || xray_dnstap_has_urls($blockUrls, true) || $hasGeo;
    if (!$hasUrls) {
        return [
            'wrote'    => false,
            'proxy'    => 0,
            'direct'   => 0,
            'block'    => 0,
            'fetched'  => 0,
            'failed'   => [],
            'has_urls' => false,
        ];
    }

    $dl = xray_dnstap_fetch_enabled_caches($proxyUrls, $directUrls, $blockUrls, $geodatUrls);

    $geoProxy = [];
    $geoDirect = [];
    $geoBlock = [];
    $failed = $dl['failed'];
    $fetched = $dl['fetched'];
    if ($hasGeo && xray_dnstap_geodat_has_any_assign($geodatUrls, true)) {
        $geo = xray_dnstap_geosite_sets($geodatUrls);
        $geoProxy = $geo['proxy'];
        $geoDirect = $geo['direct'];
        $geoBlock = $geo['block'] ?? [];
        $fetched += $geo['fetched'];
        $failed = array_merge($failed, $geo['failed']);
    }

    $sum = xray_dnstap_commit_summaries(
        $proxyExtra,
        $proxyUrls,
        $directExtra,
        $directUrls,
        $geoProxy,
        $geoDirect,
        $blockExtra,
        $blockUrls,
        $geoBlock
    );

    return [
        'wrote'    => true,
        'proxy'    => $sum['proxy']['count'],
        'direct'   => $sum['direct']['count'],
        'block'    => $sum['block']['count'],
        'fetched'  => $fetched,
        'failed'   => array_values(array_unique(array_merge($failed, $sum['failed']))),
        'has_urls' => true,
    ];
}

function xray_dnstap_rows_from_post($raw): array
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
            // Accept {key,value,enabled}, {url,enabled}, {name,action} — always keep enabled.
            if (isset($item['key']) || isset($item['value']) || isset($item['url']) || isset($item['name'])) {
                $key = (string)($item['key'] ?? $item['name'] ?? 'url');
                $val = (string)($item['value'] ?? $item['action'] ?? $item['url'] ?? '');
                $en  = array_key_exists('enabled', $item)
                    ? (xray_dnstap_row_enabled($item) ? '1' : '0')
                    : '1'; // legacy rows without flag stay enabled
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
            return xray_dnstap_rows_from_post($j);
        }
    }
    return [];
}

function xray_dnstap_urls_file_for_kind(string $kind): string
{
    $map = [
        'proxy'  => XRAY_DNSTAP_FILES['proxy_urls'],
        'direct' => XRAY_DNSTAP_FILES['direct_urls'],
        'block'  => XRAY_DNSTAP_FILES['block_urls'],
        'geodat' => XRAY_DNSTAP_FILES['geodat_urls'],
    ];
    return $map[$kind] ?? '';
}

function xray_dnstap_load_url_rows(string $kind): array
{
    $kind = strtolower($kind);
    $path = xray_dnstap_urls_file_for_kind($kind);
    if ($path === '') {
        return [];
    }
    $paths = [$path];
    if ($kind === 'proxy') {
        $paths = array_merge($paths, [
            XRAY_DNSTAP_LEGACY['blocked_urls'],
            XRAY_DNSTAP_LEGACY['urls'],
        ]);
    } elseif ($kind === 'direct') {
        $paths[] = XRAY_DNSTAP_LEGACY['unblocked_urls'];
    }
    return xray_dnstap_parse_urls(xray_dnstap_read_first($paths));
}

function xray_dnstap_save_url_rows(string $kind, array $rows): bool
{
    $path = xray_dnstap_urls_file_for_kind($kind);
    if ($path === '') {
        return false;
    }
    return xray_dnstap_write_file($path, xray_dnstap_render_urls($rows, $kind));
}

/**
 * Re-download enabled URL caches, then rebuild summaries.
 *
 * @return array{fetched:int,failed:string[]}
 */
function xray_dnstap_fetch_enabled_caches(
    array $proxyUrls,
    array $directUrls,
    array $blockUrls,
    array $geodatUrls
): array {
    $fetched = 0;
    $failed = [];
    foreach (['proxy' => $proxyUrls, 'direct' => $directUrls, 'block' => $blockUrls, 'geodat' => $geodatUrls] as $kind => $rows) {
        foreach (xray_dnstap_url_list($rows, true) as $url) {
            $dl = xray_dnstap_download_to_cache($kind, $url);
            if ($dl['ok']) {
                $fetched++;
            } else {
                $failed[] = $url;
            }
        }
    }
    return ['fetched' => $fetched, 'failed' => $failed];
}

$op = strtolower(trim((string)($argv[1] ?? 'get')));

if ($op === 'start') {
    $confPath = XRAY_DNSTAP_FILES['conf'];
    $rows = xray_dnstap_ensure_community_rows(xray_dnstap_parse_toml(xray_dnstap_read_file($confPath)));
    if ($rows !== [] && is_file($confPath)) {
        xray_dnstap_write_file($confPath, xray_dnstap_render_toml($rows));
        echo "dnstap: proxy_communities AS:777 direct_communities AS:555 block_communities AS:888\n";
    }
    xray_dnstap_unbound_activate();
    echo "OK\n";
    exit(0);
}

if ($op === 'stop') {
    xray_dnstap_unbound_deactivate();
    echo "OK\n";
    exit(0);
}

if ($op === 'fetch') {
    $cfg = OPNsense\Core\Config::getInstance()->object();
    if ((string)($cfg->OPNsense->xray->general->dnstap_bgp_enabled ?? '0') !== '1') {
        echo "OK dnstap_bgp disabled — skip fetch\n";
        exit(0);
    }
    $result = xray_dnstap_refresh_from_urls();
    if (!$result['has_urls'] && !$result['wrote']) {
        echo "OK no enabled domain list URLs — skip download\n";
        exit(0);
    }
    echo "OK fetched {$result['fetched']} enabled URL(s), {$result['proxy']} PROXY + {$result['direct']} DIRECT + {$result['block']} BLOCK\n";
    if ($result['failed'] !== []) {
        echo "WARN could not download: " . implode(' ', $result['failed']) . "\n";
    }
    if (xray_dnstap_running()) {
        if (xray_dnstap_reload()) {
            echo "OK dnstap_bgp reload\n";
        } else {
            echo "ERROR: dnstap_bgp reload failed\n";
            exit(1);
        }
    } else {
        echo "OK files written, dnstap_bgp not running\n";
    }
    exit(0);
}

if ($op === 'fetchone') {
    $raw = is_readable(XRAY_DNSTAP_FETCHONE_STAGED)
        ? (string)file_get_contents(XRAY_DNSTAP_FETCHONE_STAGED)
        : '';
    @unlink(XRAY_DNSTAP_FETCHONE_STAGED);
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
    $enabled = array_key_exists('enabled', $j) ? xray_dnstap_row_enabled(['enabled' => $j['enabled']]) : null;
    $rows = xray_dnstap_load_url_rows($kind);
    $rows = xray_dnstap_upsert_url_row($rows, $url, $enabled);
    xray_dnstap_save_url_rows($kind, $rows);
    $dl = xray_dnstap_download_to_cache($kind, $url);
    if (!$dl['ok']) {
        echo json_encode(['result' => 'failed', 'message' => $dl['message'], 'url' => $url, 'kind' => $kind], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    $rowEn = false;
    foreach ($rows as $r) {
        if (strcasecmp(trim((string)($r['value'] ?? '')), $url) === 0) {
            $rowEn = xray_dnstap_row_enabled($r);
            break;
        }
    }
    if ($rowEn) {
        // Rebuild summarized domain files from enabled caches
        xray_dnstap_refresh_from_urls();
        if (xray_dnstap_running()) {
            xray_dnstap_reload();
        }
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

if ($op === 'geodatcats') {
    if (!is_readable(XRAY_DNSTAP_GEODAT_STAGED)) {
        echo json_encode(['result' => 'failed', 'message' => 'No staged geosite request']) . "\n";
        exit(1);
    }
    $j = json_decode((string)file_get_contents(XRAY_DNSTAP_GEODAT_STAGED), true);
    @unlink(XRAY_DNSTAP_GEODAT_STAGED);
    if (!is_array($j)) {
        echo json_encode(['result' => 'failed', 'message' => 'Invalid staged JSON']) . "\n";
        exit(1);
    }
    xray_dnstap_ensure_domain_files();
    $urlRows = xray_dnstap_rows_from_post($j['geodat_urls'] ?? $j['urls'] ?? []);
    if ($urlRows === []) {
        $urlRows = xray_dnstap_parse_urls(xray_dnstap_read_file(XRAY_DNSTAP_FILES['geodat_urls']));
    }
    $allUrls = xray_dnstap_url_list($urlRows);
    $target = trim((string)($j['geodat_url'] ?? ''));
    if ($target === '' || !preg_match('#^https?://#i', $target)) {
        $target = $allUrls[0] ?? '';
    }
    $save = array_key_exists('geodat_categories', $j) || array_key_exists('categories', $j);
    $oneRow = $target !== '' ? [['key' => 'url', 'value' => $target]] : [];
    $assign = $save
        ? xray_geosite_assignments_from_rows(xray_dnstap_rows_from_post($j['geodat_categories'] ?? $j['categories'] ?? []))
        : ($target !== '' ? xray_dnstap_geodat_assign_for_url($target) : []);
    if ($save && $target !== '') {
        xray_dnstap_write_file(XRAY_DNSTAP_FILES['geodat_urls'], xray_dnstap_render_urls($urlRows, 'geodat'));
        xray_dnstap_write_file(xray_dnstap_geodat_cat_path($target), xray_geosite_render_assignments($assign, $target));
    }
    $load = xray_dnstap_geosite_load($oneRow);
    $saved = false;
    if ($save) {
        $geoAll = xray_dnstap_geosite_sets($urlRows);
        $proxyExtra = xray_dnstap_parse_domains(xray_dnstap_read_first([
            XRAY_DNSTAP_FILES['proxy_extra'],
            XRAY_DNSTAP_LEGACY['blocked_extra'],
            XRAY_DNSTAP_LEGACY['extra'],
        ]));
        $proxyUrls = xray_dnstap_parse_urls(xray_dnstap_read_first([
            XRAY_DNSTAP_FILES['proxy_urls'],
            XRAY_DNSTAP_LEGACY['blocked_urls'],
            XRAY_DNSTAP_LEGACY['urls'],
        ]));
        $directExtra = xray_dnstap_parse_domains(xray_dnstap_read_first([
            XRAY_DNSTAP_FILES['direct_extra'],
            XRAY_DNSTAP_LEGACY['unblocked_extra'],
        ]));
        $directUrls = xray_dnstap_parse_urls(xray_dnstap_read_first([
            XRAY_DNSTAP_FILES['direct_urls'],
            XRAY_DNSTAP_LEGACY['unblocked_urls'],
        ]));
        $blockExtra = xray_dnstap_parse_domains(xray_dnstap_read_file(XRAY_DNSTAP_FILES['block_extra']));
        $blockUrls = xray_dnstap_parse_urls(xray_dnstap_read_file(XRAY_DNSTAP_FILES['block_urls']));
        xray_dnstap_commit_summaries(
            $proxyExtra,
            $proxyUrls,
            $directExtra,
            $directUrls,
            $geoAll['proxy'],
            $geoAll['direct'],
            $blockExtra,
            $blockUrls,
            $geoAll['block'] ?? []
        );
        $saved = true;
        if (xray_dnstap_running()) {
            xray_dnstap_sighup();
        }
    }
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'result'     => $load['failed'] !== [] && $load['cats'] === [] ? 'failed' : 'ok',
        'saved'      => $saved,
        'geodat_url' => $target,
        'categories' => xray_dnstap_geosite_ui_rows($load['cats'], $assign),
        'fetched'    => $load['fetched'],
        'failed'     => $load['failed'],
        'message'    => $load['failed'] !== [] && $load['cats'] === []
            ? ('Could not parse geosite.dat: ' . implode(' ', $load['failed']))
            : '',
    ], $flags) . "\n";
    exit(0);
}

if ($op === 'write') {
    if (!is_readable(XRAY_DNSTAP_STAGED)) {
        echo "OK\n";
        exit(0);
    }
    $j = json_decode((string)file_get_contents(XRAY_DNSTAP_STAGED), true);
    @unlink(XRAY_DNSTAP_STAGED);
    if (!is_array($j)) {
        echo "ERROR: invalid staged JSON\n";
        exit(1);
    }
    $proxyExtra  = xray_dnstap_rows_from_post($j['proxy'] ?? $j['blocked'] ?? $j['domains'] ?? []);
    $proxyUrls   = xray_dnstap_rows_from_post($j['proxy_urls'] ?? $j['blocked_urls'] ?? $j['urls'] ?? []);
    $directExtra = xray_dnstap_rows_from_post($j['direct'] ?? $j['unblocked'] ?? []);
    $directUrls  = xray_dnstap_rows_from_post($j['direct_urls'] ?? $j['unblocked_urls'] ?? []);
    $blockExtra  = xray_dnstap_rows_from_post($j['block'] ?? []);
    $blockUrls   = xray_dnstap_rows_from_post($j['block_urls'] ?? []);
    $geodatUrls  = array_key_exists('geodat_urls', $j)
        ? xray_dnstap_rows_from_post($j['geodat_urls'])
        : xray_dnstap_parse_urls(xray_dnstap_read_file(XRAY_DNSTAP_FILES['geodat_urls']));
    $confPosted  = xray_dnstap_rows_from_post($j['conf'] ?? []);
    $confRows    = $confPosted !== []
        ? xray_dnstap_conf_merge_managed($confPosted)
        : xray_dnstap_conf_merge_managed(xray_dnstap_parse_toml(xray_dnstap_read_file(XRAY_DNSTAP_FILES['conf'])));
    $geoProxy = [];
    $geoDirect = [];
    $geoBlock = [];
    $geoFailed = [];
    $geoFetched = 0;
    if (xray_dnstap_has_urls($geodatUrls, true) && xray_dnstap_geodat_has_any_assign($geodatUrls, true)) {
        $geo = xray_dnstap_geosite_sets($geodatUrls);
        $geoProxy = $geo['proxy'];
        $geoDirect = $geo['direct'];
        $geoBlock = $geo['block'] ?? [];
        $geoFailed = $geo['failed'];
        $geoFetched = $geo['fetched'];
    }
    $sum = xray_dnstap_commit_summaries(
        $proxyExtra,
        $proxyUrls,
        $directExtra,
        $directUrls,
        $geoProxy,
        $geoDirect,
        $blockExtra,
        $blockUrls,
        $geoBlock
    );
    $map = [
        'conf'        => xray_dnstap_render_toml($confRows),
        'proxy_extra' => xray_dnstap_render_domains($proxyExtra),
        'direct_extra'=> xray_dnstap_render_domains($directExtra),
        'block_extra' => xray_dnstap_render_domains($blockExtra),
        'proxy_urls'  => xray_dnstap_render_urls($proxyUrls, 'proxy'),
        'direct_urls' => xray_dnstap_render_urls($directUrls, 'direct'),
        'block_urls'  => xray_dnstap_render_urls($blockUrls, 'block'),
        'geodat_urls' => xray_dnstap_render_urls($geodatUrls, 'geodat'),
    ];
    foreach ($map as $key => $body) {
        $limit = XRAY_DNSTAP_MAX;
        if (strlen($body) > $limit) {
            echo "ERROR: {$key} too large\n";
            exit(1);
        }
        if (!xray_dnstap_write_file(XRAY_DNSTAP_FILES[$key], $body)) {
            echo "ERROR: cannot write " . XRAY_DNSTAP_FILES[$key] . "\n";
            exit(1);
        }
    }
    echo "OK merged {$sum['proxy']['count']} PROXY + {$sum['direct']['count']} DIRECT + {$sum['block']['count']} BLOCK"
        . " from " . ($sum['fetched'] + $geoFetched) . " URL(s)\n";
    xray_dnstap_sync_rc_from_bgp($confRows);
    $failed = array_merge($sum['failed'], $geoFailed);
    if ($failed !== []) {
        echo "WARN could not download: " . implode(' ', $failed) . "\n";
    }
    $dnstapOn = xray_dnstap_rc_is_enabled(XRAY_DNSTAP_FILES['rc']);
    if ($dnstapOn) {
        xray_dnstap_sync_bird(true);
        echo "OK BIRD dnstap peer synced (IPv6 from dnstap-bgp.conf), birdc configure\n";
        if (xray_dnstap_running()) {
            if (xray_dnstap_sighup()) {
                echo "OK dnstap_bgp running — sent SIGHUP (Unbound not restarted)\n";
            } else {
                echo "ERROR: dnstap_bgp running but SIGHUP failed\n";
                exit(1);
            }
        } else {
            echo "OK files written, dnstap_bgp not running (Unbound not restarted)\n";
        }
    } elseif (xray_dnstap_running()) {
        xray_dnstap_unbound_deactivate();
        echo "OK dnstap_bgp stopped, Unbound DNSTap include removed\n";
    } else {
        xray_dnstap_sync_bird(false);
        echo "OK files written, dnstap_bgp not running\n";
    }
    exit(0);
}

$proxyUrlsText = xray_dnstap_read_first([
    XRAY_DNSTAP_FILES['proxy_urls'],
    XRAY_DNSTAP_LEGACY['blocked_urls'],
    XRAY_DNSTAP_LEGACY['urls'],
]);
$proxyExtraText = xray_dnstap_read_first([
    XRAY_DNSTAP_FILES['proxy_extra'],
    XRAY_DNSTAP_LEGACY['blocked_extra'],
    XRAY_DNSTAP_LEGACY['extra'],
]);
$directUrlsText = xray_dnstap_read_first([
    XRAY_DNSTAP_FILES['direct_urls'],
    XRAY_DNSTAP_LEGACY['unblocked_urls'],
]);
$directExtraText = xray_dnstap_read_first([
    XRAY_DNSTAP_FILES['direct_extra'],
    XRAY_DNSTAP_LEGACY['unblocked_extra'],
]);
$blockUrlsText = xray_dnstap_read_file(XRAY_DNSTAP_FILES['block_urls']);
$blockExtraText = xray_dnstap_read_file(XRAY_DNSTAP_FILES['block_extra']);
$geodatUrlsText = xray_dnstap_read_file(XRAY_DNSTAP_FILES['geodat_urls']);
$proxyRows   = xray_dnstap_parse_domains($proxyExtraText);
$directRows  = xray_dnstap_parse_domains($directExtraText);
$blockRows   = xray_dnstap_parse_domains($blockExtraText);
$proxyUrlRows = xray_dnstap_parse_urls($proxyUrlsText);
$directUrlRows = xray_dnstap_parse_urls($directUrlsText);
$blockUrlRows = xray_dnstap_parse_urls($blockUrlsText);
$geodatUrlRows = xray_dnstap_parse_urls($geodatUrlsText);
if (function_exists('xray_dnstap_with_default_proxy_urls')) {
    $proxyUrlRows = xray_dnstap_with_default_proxy_urls($proxyUrlRows);
}
if (function_exists('xray_dnstap_with_default_geodat_urls')) {
    $geodatUrlRows = xray_dnstap_with_default_geodat_urls($geodatUrlRows);
}

echo json_encode([
    'result'          => 'ok',
    'running'         => xray_dnstap_running(),
    'paths'           => XRAY_DNSTAP_FILES,
    'conf'            => xray_dnstap_conf_for_ui(xray_dnstap_parse_toml(xray_dnstap_read_file(XRAY_DNSTAP_FILES['conf']))),
    'proxy'           => $proxyRows,
    'direct'          => $directRows,
    'block'           => $blockRows,
    'proxy_urls'      => $proxyUrlRows,
    'direct_urls'     => $directUrlRows,
    'block_urls'      => $blockUrlRows,
    'geodat_urls'     => $geodatUrlRows,
    'domains'         => $proxyRows,
    'urls'            => $proxyUrlRows,
    'proxy_count'     => xray_dnstap_count_domains(xray_dnstap_read_first([
        XRAY_DNSTAP_FILES['proxy'],
        XRAY_DNSTAP_LEGACY['domains'],
    ])),
    'direct_count'    => xray_dnstap_count_domains(xray_dnstap_read_first([
        XRAY_DNSTAP_FILES['direct'],
        XRAY_DNSTAP_LEGACY['unblocked'],
    ])),
    'block_count'     => xray_dnstap_count_domains(xray_dnstap_read_file(XRAY_DNSTAP_FILES['block'])),
    'domains_count'   => xray_dnstap_count_domains(xray_dnstap_read_first([
        XRAY_DNSTAP_FILES['proxy'],
        XRAY_DNSTAP_LEGACY['domains'],
    ])),
    'rc'              => xray_dnstap_parse_rc(xray_dnstap_read_file(XRAY_DNSTAP_FILES['rc'])),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
