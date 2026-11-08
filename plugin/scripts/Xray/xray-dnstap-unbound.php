<?php
/**
 * Wire Unbound DNSTap include to dnstap-bgp socket on start; undo on stop.
 */

const XRAY_DNSTAP_UNBOUND_SAMPLE = '/usr/local/etc/unbound.opnsense.d/dnstap.conf.sample';
const XRAY_DNSTAP_UNBOUND_CONF   = '/usr/local/etc/unbound.opnsense.d/dnstap.conf';
const XRAY_DNSTAP_SOCK_DIR       = '/var/unbound/var/run/dnstap-bgp';
/** Host path for dnstap-bgp. Unbound chroots to /var/unbound and uses SOCK_CHROOT. */
const XRAY_DNSTAP_SOCK           = '/var/unbound/var/run/dnstap-bgp/dnstap.sock';
const XRAY_DNSTAP_SOCK_CHROOT    = '/var/run/dnstap-bgp/dnstap.sock';
const XRAY_DNSTAP_BGP_CONF       = '/usr/local/etc/dnstap-bgp/dnstap-bgp.conf';
const XRAY_DNSTAP_BGP_SAMPLE     = '/usr/local/etc/dnstap-bgp/dnstap-bgp.conf.sample';
const XRAY_DNSTAP_DEFAULT_PROXY_URLS = [
    'https://github.com/1andrevich/Re-filter-lists/releases/download/01082026/domains_all.lst',
    'https://community.antifilter.download/list/domains.lst',
];
const XRAY_DNSTAP_DEFAULT_GEODAT_URLS = [
    'https://github.com/hydraponique/roscomvpn-geosite/releases/latest/download/geosite.dat',
];
/** Default geosite category → proxy|direct|block for XRAY_DNSTAP_DEFAULT_GEODAT_URLS[0]. */
const XRAY_DNSTAP_DEFAULT_GEODAT_CATEGORIES = [
    'apple'                 => 'direct',
    'category-ads'          => 'block',
    'category-geoblock-ru'  => 'proxy',
    'category-ru'           => 'direct',
    'epicgames'             => 'direct',
    'escapefromtarkov'      => 'direct',
    'faceit'                => 'direct',
    'github'                => 'proxy',
    'google-deepmind'       => 'proxy',
    'google-play'           => 'proxy',
    'microsoft'             => 'direct',
    'origin'                => 'direct',
    'pinterest'             => 'direct',
    'private'               => 'direct',
    'push'                  => 'direct',
    'riot'                  => 'direct',
    'steam'                 => 'direct',
    'telegram'              => 'proxy',
    'torrent'               => 'proxy',
    'twitch'                => 'direct',
    'twitch-ads'            => 'proxy',
    'whitelist'             => 'direct',
    'win-spy'               => 'block',
    'youtube'               => 'proxy',
];
const XRAY_DNSTAP_PERM           = '0666';
const XRAY_DNSTAP_RC             = '/usr/local/etc/rc.conf.d/dnstap_bgp';

/**
 * Rewrite an rc.conf-style assignment in place. Do not use sysrc: a
 * corrupted line like name=""YES""NO"" is invisible to sysrc -x, so the
 * next set appends another token. Strip every name=... line then append
 * a single name="VALUE".
 */
function xray_rc_conf_delete_var(string $path, string $name): void
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || !is_readable($path)) {
        return;
    }
    $text = (string)file_get_contents($path);
    $new = preg_replace('/^[ \t]*' . preg_quote($name, '/') . '[ \t]*=[^\n]*\r?\n?/m', '', $text);
    if ($new === null || $new === $text) {
        return;
    }
    if (file_put_contents($path, $new) === false) {
        echo "dnstap: cannot rewrite {$path}\n";
    }
}

function xray_sysrc_set(string $name, string $value, ?string $file = null): void
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        return;
    }
    $path = ($file !== null && $file !== '') ? $file : '/etc/rc.conf';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    xray_rc_conf_delete_var($path, $name);
    $line = $name . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . "\"\n";
    $body = is_readable($path) ? (string)file_get_contents($path) : '';
    $body = rtrim($body);
    $body = ($body === '') ? $line : ($body . "\n" . $line);
    if (file_put_contents($path, $body) === false) {
        echo "dnstap: cannot write {$path}\n";
        return;
    }
    @chmod($path, 0644);
}

function xray_dnstap_strip_cidr(string $s): string
{
    $s = trim($s, " \t\"'");
    if (preg_match('/^([^\/]+)\//', $s, $m)) {
        return $m[1];
    }
    return $s;
}

function xray_dnstap_bird_local_as(): int
{
    $as = 65103;
    try {
        $cfg = \OPNsense\Core\Config::getInstance();
        if (method_exists($cfg, 'forceReload')) {
            $cfg->forceReload();
        }
        $peers = $cfg->object()->OPNsense->xray->bgppeers->peer ?? null;
        if ($peers) {
            foreach ($peers as $peer) {
                if (strcasecmp(trim((string)($peer->name ?? '')), 'dnstap') === 0) {
                    continue;
                }
                $las = (int)($peer->local_as ?? 0);
                if ($las > 0) {
                    return $las;
                }
            }
        }
    } catch (\Throwable $e) {
    }
    return $as;
}

function xray_dnstap_ip_with_prefix(string $ip, int $prefix = 30): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return '';
    }
    if (strpos($ip, '/') !== false) {
        return $ip;
    }
    return $ip . '/' . $prefix;
}

function xray_dnstap_first_peer_ip(string $raw): string
{
    if (preg_match('/peers\s*=\s*\[(.*?)\]/s', $raw, $m)) {
        if (preg_match('/"([^"]+)"/', $m[1], $q)) {
            return xray_dnstap_strip_cidr($q[1]);
        }
        if (preg_match('/([0-9]{1,3}(?:\.[0-9]{1,3}){3})/', $m[1], $q)) {
            return $q[1];
        }
    }
    return '';
}

function xray_dnstap_sync_rc_from_bgp(?array $rows = null): void
{
    $jail = '';
    $host = '';
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $k = (string)($row['key'] ?? '');
            $v = (string)($row['value'] ?? '');
            if ($k === 'bgp.sourceIP' || $k === 'bgp.sourceip') {
                $jail = xray_dnstap_strip_cidr($v);
            }
            if ($k === 'bgp.peers') {
                $parts = preg_split('/\s*,\s*/', $v);
                $host = xray_dnstap_strip_cidr((string)($parts[0] ?? ''));
            }
        }
    } elseif (is_readable(XRAY_DNSTAP_BGP_CONF)) {
        $raw = (string)file_get_contents(XRAY_DNSTAP_BGP_CONF);
        $bgp = xray_dnstap_toml_section_kv($raw, 'bgp');
        foreach (['sourceIP', 'sourceip'] as $k) {
            if (!empty($bgp[$k])) {
                $jail = xray_dnstap_strip_cidr((string)$bgp[$k]);
                break;
            }
        }
        $host = xray_dnstap_first_peer_ip($raw);
    }
    $jailCidr = xray_dnstap_ip_with_prefix($jail);
    $hostCidr = xray_dnstap_ip_with_prefix($host);
    if ($jailCidr === '' && $hostCidr === '') {
        return;
    }
    $dir = dirname(XRAY_DNSTAP_RC);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if ($hostCidr !== '') {
        xray_sysrc_set('dnstap_bgp_host_ip', $hostCidr, XRAY_DNSTAP_RC);
        echo "dnstap: rc dnstap_bgp_host_ip={$hostCidr} (from bgp.peers)\n";
    }
    if ($jailCidr !== '') {
        xray_sysrc_set('dnstap_bgp_jail_ip', $jailCidr, XRAY_DNSTAP_RC);
        echo "dnstap: rc dnstap_bgp_jail_ip={$jailCidr} (from bgp.sourceIP)\n";
    }
}

function xray_dnstap_bird_neighbor_params(): array
{
    $host = '192.168.113.1';
    $jail = '192.168.113.2';
    $ipv6 = true;
    $localAs = xray_dnstap_bird_local_as();
    if (is_readable(XRAY_DNSTAP_BGP_CONF)) {
        $raw = (string)file_get_contents(XRAY_DNSTAP_BGP_CONF);
        $bgp = xray_dnstap_toml_section_kv($raw, 'bgp');
        foreach (['sourceIP', 'sourceip', 'nextHop', 'nexthop'] as $k) {
            if (!empty($bgp[$k])) {
                $jail = xray_dnstap_strip_cidr((string)$bgp[$k]);
                break;
            }
        }
        $peer = xray_dnstap_first_peer_ip($raw);
        if ($peer !== '') {
            $host = $peer;
        }
        if (preg_match('/^ipv6\s*=\s*"?(true|false|1|0|yes|no)"?/mi', $raw, $m)) {
            $ipv6 = in_array(strtolower($m[1]), ['true', '1', 'yes'], true);
        }
    }
    return [
        'host'        => $host,
        'jail'        => $jail,
        'neighbor_as' => $localAs,
        'local_as'    => $localAs,
        'ipv6'        => $ipv6,
    ];
}

function xray_dnstap_sync_bird(bool $enable): void
{
    try {
        $script = __DIR__ . '/xray-bird-peers.php';
        if (is_readable($script)) {
            require_once $script;
        }
        if (!function_exists('xray_dnstap_sync_bird_peer')) {
            echo "dnstap: BIRD peer sync skipped — xray-bird-peers.php unavailable\n";
            return;
        }
        xray_dnstap_sync_bird_peer($enable, xray_dnstap_bird_neighbor_params());
    } catch (\Throwable $e) {
        echo "WARN BIRD dnstap peer sync failed: " . $e->getMessage() . "\n";
    }
}

function xray_dnstap_toml_format_value(string $key, string $v): string
{
    $v = trim($v);
    $name = $key;
    $dot = strrpos($key, '.');
    if ($dot !== false) {
        $name = substr($key, $dot + 1);
    }
    if ($v === 'true' || $v === 'false') {
        return $v;
    }
    if ($name === 'as' && preg_match('/^-?\d+$/', $v)) {
        return (string)((int)$v);
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

function xray_dnstap_toml_kv_line(string $name, string $v): string
{
    return $name . ' = ' . xray_dnstap_toml_format_value($name, $v);
}

function xray_dnstap_toml_upsert_section(string $text, string $section, array $kv): string
{
    $lines = preg_split("/\r\n|\n|\r/", $text);
    if ($lines === false) {
        $lines = [];
    }
    $out = [];
    $in = false;
    $seen = [];
    $closed = false;
    $hadSection = false;

    $flushMissing = static function () use (&$out, &$seen, $kv): void {
        foreach ($kv as $k => $v) {
            if (empty($seen[$k])) {
                $out[] = xray_dnstap_toml_kv_line($k, (string)$v);
            }
        }
    };

    foreach ($lines as $line) {
        $trim = trim($line);
        if (preg_match('/^\[(.+)\]$/', $trim, $m)) {
            if ($in) {
                $flushMissing();
                $in = false;
                $closed = true;
            }
            $in = ($m[1] === $section);
            if ($in) {
                $hadSection = true;
                $seen = [];
            }
            $out[] = $line;
            continue;
        }
        if ($in && preg_match('/^([A-Za-z0-9_]+)\s*=/', $trim, $km) && isset($kv[$km[1]])) {
            $k = $km[1];
            $out[] = xray_dnstap_toml_kv_line($k, (string)$kv[$k]);
            $seen[$k] = true;
            continue;
        }
        $out[] = $line;
    }
    if ($in) {
        $flushMissing();
        $closed = true;
    }
    if (!$hadSection) {
        if ($out !== [] && trim((string)end($out)) !== '') {
            $out[] = '';
        }
        $out[] = '[' . $section . ']';
        foreach ($kv as $k => $v) {
            $out[] = xray_dnstap_toml_kv_line($k, (string)$v);
        }
    }
    unset($closed);
    return implode("\n", $out) . "\n";
}

function xray_dnstap_toml_section_kv(string $text, string $section): array
{
    $kv = [];
    $in = false;
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $trim = trim($line);
        if ($trim === '' || ($trim[0] ?? '') === '#') {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $trim, $m)) {
            $in = ($m[1] === $section);
            continue;
        }
        if (!$in) {
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $trim, $km)) {
            $v = trim($km[2]);
            if ($v !== '' && $v[0] === '"' && substr($v, -1) === '"') {
                $v = stripcslashes(substr($v, 1, -1));
            }
            $kv[$km[1]] = $v;
        }
    }
    return $kv;
}

function xray_dnstap_toml_upsert_root(string $text, array $kv): string
{
    $lines = preg_split("/\r\n|\n|\r/", $text);
    if ($lines === false) {
        $lines = [];
    }
    $out = [];
    $seen = [];
    $inSection = false;
    foreach ($lines as $line) {
        $trim = trim($line);
        if (preg_match('/^\[.+\]$/', $trim)) {
            $inSection = true;
            $out[] = $line;
            continue;
        }
        if (!$inSection && preg_match('/^([A-Za-z0-9_]+)\s*=/', $trim, $m) && isset($kv[$m[1]])) {
            $k = $m[1];
            $out[] = xray_dnstap_toml_kv_line($k, (string)$kv[$k]);
            $seen[$k] = true;
            continue;
        }
        $out[] = $line;
    }
    $missing = [];
    foreach ($kv as $k => $v) {
        if (empty($seen[$k])) {
            $missing[] = xray_dnstap_toml_kv_line($k, (string)$v);
        }
    }
    if ($missing === []) {
        return implode("\n", $out) . "\n";
    }
    $final = [];
    $inserted = false;
    foreach ($out as $line) {
        if (!$inserted && preg_match('/^\[.+\]$/', trim($line))) {
            foreach ($missing as $ins) {
                $final[] = $ins;
            }
            $final[] = '';
            $inserted = true;
        }
        $final[] = $line;
    }
    if (!$inserted) {
        if ($final !== [] && trim((string)end($final)) !== '') {
            $final[] = '';
        }
        foreach ($missing as $ins) {
            $final[] = $ins;
        }
    }
    return implode("\n", $final) . "\n";
}

function xray_dnstap_write_bgp_listen(): void
{
    $conf = XRAY_DNSTAP_BGP_CONF;
    $dir = dirname($conf);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $text = is_readable($conf) ? (string)file_get_contents($conf) : '';
    $kv = ['listen' => XRAY_DNSTAP_SOCK, 'perm' => XRAY_DNSTAP_PERM];
    $new = xray_dnstap_toml_upsert_section($text, 'dnstap', $kv);
    if (file_put_contents($conf, $new) === false) {
        echo "dnstap: cannot write {$conf}\n";
        return;
    }
    echo "dnstap: {$conf} [dnstap] listen={$kv['listen']} perm={$kv['perm']}\n";
}

function xray_dnstap_write_unbound_dnstap(bool $enable): void
{
    $dst = XRAY_DNSTAP_UNBOUND_CONF;
    $dir = dirname($dst);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $yesno = $enable ? 'yes' : 'no';
    $body = implode("\n", [
        '# managed by os-xray — do not paste into Unbound GUI Custom options',
        '# Unbound chroot /var/unbound: socket path below is inside the jail.',
        '# Host / dnstap-bgp listen: ' . XRAY_DNSTAP_SOCK,
        '',
        'dnstap:',
        "\tdnstap-enable: {$yesno}",
        "\tdnstap-socket-path: \"" . XRAY_DNSTAP_SOCK_CHROOT . '"',
        "\tdnstap-log-client-response-messages: yes",
        '',
    ]);
    if (file_put_contents($dst, $body) === false) {
        echo "dnstap: cannot write {$dst}\n";
        return;
    }
    @chmod($dst, 0644);
    echo "dnstap: {$dst} dnstap-enable={$yesno} socket=" . XRAY_DNSTAP_SOCK_CHROOT . "\n";
}

function xray_unbound_reload(): void
{
    $cmds = [
        '/usr/local/sbin/configctl unbound reload',
        '/usr/sbin/service unbound reload',
        '/usr/local/sbin/configctl unbound restart',
    ];
    foreach ($cmds as $cmd) {
        $out = [];
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc === 0) {
            echo "dnstap: unbound reloaded ({$cmd})\n";
            return;
        }
        echo "dnstap: {$cmd} failed: " . implode(' ', $out) . "\n";
    }
}

function xray_dnstap_migrate_named_file(string $old, string $new, bool $removeOld = false): void
{
    if (is_readable($old) && !is_file($new)) {
        if (@copy($old, $new)) {
            @chmod($new, 0644);
            echo "dnstap: migrated {$old} → {$new}\n";
        }
    }
    if ($removeOld && is_file($old)) {
        @unlink($old);
        echo "dnstap: removed {$old}\n";
    }
}

function xray_dnstap_ensure_domain_files(): void
{
    $dir = '/usr/local/etc/dnstap-bgp';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        echo "dnstap: cannot mkdir {$dir}\n";
        return;
    }
    $catDir = $dir . '/geodat-categories';
    if (!is_dir($catDir) && !@mkdir($catDir, 0755, true)) {
        echo "dnstap: cannot mkdir {$catDir}\n";
    }
    xray_dnstap_migrate_named_file($dir . '/domains.txt', $dir . '/proxy.txt');
    xray_dnstap_migrate_named_file($dir . '/blocked.txt', $dir . '/proxy.txt', true);
    xray_dnstap_migrate_named_file($dir . '/unblocked.txt', $dir . '/direct.txt');
    xray_dnstap_migrate_named_file($dir . '/blocked-extra.txt', $dir . '/proxy-extra.txt');
    xray_dnstap_migrate_named_file($dir . '/unblocked-extra.txt', $dir . '/direct-extra.txt');
    xray_dnstap_migrate_named_file($dir . '/blocked-urls.txt', $dir . '/proxy-urls.txt');
    xray_dnstap_migrate_named_file($dir . '/unblocked-urls.txt', $dir . '/direct-urls.txt');
    $legacy = $dir . '/domains.txt';
    $proxy = $dir . '/proxy.txt';
    if (is_readable($legacy) && !is_file($proxy)) {
        @copy($legacy, $proxy);
        echo "dnstap: migrated {$legacy} → {$proxy}\n";
    }
    $emptyDomains = "# One lowercase FQDN per line. IDN/punycode is not supported.\n";
    $emptyUrls = "# HTTP(S) URLs of domain lists. One URL per line.\n";
    $proxyUrls = $emptyUrls . implode("\n", XRAY_DNSTAP_DEFAULT_PROXY_URLS) . "\n";
    $geodatUrls = "# HTTP(S) URLs of geosite.dat files. One URL per line.\n"
        . implode("\n", XRAY_DNSTAP_DEFAULT_GEODAT_URLS) . "\n";
    $files = [
        $proxy                        => $emptyDomains,
        $dir . '/direct.txt'          => $emptyDomains,
        $dir . '/block.txt'           => $emptyDomains,
        $dir . '/proxy-extra.txt'     => $emptyDomains,
        $dir . '/direct-extra.txt'    => $emptyDomains,
        $dir . '/block-extra.txt'     => $emptyDomains,
        $dir . '/proxy-urls.txt'      => $proxyUrls,
        $dir . '/direct-urls.txt'     => $emptyUrls,
        $dir . '/block-urls.txt'      => $emptyUrls,
        $dir . '/geodat-urls.txt'     => $geodatUrls,
    ];
    foreach ($files as $path => $body) {
        if (is_file($path)) {
            continue;
        }
        if (@file_put_contents($path, $body) === false) {
            echo "dnstap: cannot create {$path}\n";
            continue;
        }
        @chmod($path, 0644);
        echo "dnstap: created empty {$path}\n";
    }
    xray_dnstap_seed_default_urls($dir . '/proxy-urls.txt', XRAY_DNSTAP_DEFAULT_PROXY_URLS, 'PROXY');
    xray_dnstap_seed_default_urls($dir . '/geodat-urls.txt', XRAY_DNSTAP_DEFAULT_GEODAT_URLS, 'geodat');
    $legacyCats = $dir . '/geodat-categories.txt';
    $defaultGeo = XRAY_DNSTAP_DEFAULT_GEODAT_URLS[0];
    $perFile = $catDir . '/' . sha1(strtolower(rtrim($defaultGeo, '/'))) . '.txt';
    if (is_readable($legacyCats) && !is_file($perFile)) {
        @copy($legacyCats, $perFile);
        echo "dnstap: migrated {$legacyCats} → {$perFile}\n";
    }
    xray_dnstap_seed_default_geodat_categories();
}

function xray_dnstap_seed_default_urls(string $path, array $urls, string $kind): void
{
    $raw = is_readable($path) ? (string)file_get_contents($path) : '';
    $have = [];
    foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
        $trim = trim($line);
        if ($trim !== '') {
            $have[strtolower($trim)] = true;
        }
    }
    $add = [];
    foreach ($urls as $url) {
        if (!isset($have[strtolower($url)])) {
            $add[] = $url;
        }
    }
    if ($add === []) {
        return;
    }
    $body = $raw === ''
        ? "# HTTP(S) URLs of domain lists. One URL per line.\n" . implode("\n", $add) . "\n"
        : rtrim($raw) . "\n" . implode("\n", $add) . "\n";
    if (@file_put_contents($path, $body) !== false) {
        @chmod($path, 0644);
        echo "dnstap: seeded default {$kind} URL(s) in {$path}\n";
    }
}

/**
 * Seed default category assignments for the default hydraponique geosite.dat URL.
 * Existing assignments are kept; only missing category names are added.
 */
function xray_dnstap_seed_default_geodat_categories(): void
{
    $url = XRAY_DNSTAP_DEFAULT_GEODAT_URLS[0] ?? '';
    if ($url === '') {
        return;
    }
    $geosite = __DIR__ . '/xray-dnstap-geosite.php';
    if (!function_exists('xray_geosite_render_assignments') && is_readable($geosite)) {
        require_once $geosite;
    }
    if (!function_exists('xray_dnstap_geodat_cat_path') || !function_exists('xray_geosite_render_assignments')) {
        return;
    }
    $dir = xray_dnstap_geodat_cat_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        echo "dnstap: cannot mkdir {$dir}\n";
        return;
    }
    $path = xray_dnstap_geodat_cat_path($url);
    $have = [];
    if (is_readable($path) && function_exists('xray_geosite_parse_assignments')) {
        $have = xray_geosite_parse_assignments((string)file_get_contents($path));
    }
    $map = $have;
    $added = 0;
    foreach (XRAY_DNSTAP_DEFAULT_GEODAT_CATEGORIES as $name => $action) {
        $key = strtolower(trim((string)$name));
        if ($key === '' || isset($map[$key])) {
            continue;
        }
        $map[$key] = $action;
        $added++;
    }
    if ($added === 0 && is_file($path)) {
        return;
    }
    $body = xray_geosite_render_assignments($map, $url);
    if (@file_put_contents($path, $body) !== false) {
        @chmod($path, 0644);
        echo "dnstap: seeded {$added} default geodat categor" . ($added === 1 ? 'y' : 'ies')
            . " for {$url}\n";
    }
}

function xray_dnstap_with_default_urls(array $rows, array $defaults): array
{
    $have = [];
    foreach ($rows as $row) {
        $v = strtolower(trim((string)($row['value'] ?? '')));
        if ($v !== '') {
            $have[$v] = true;
        }
    }
    foreach ($defaults as $url) {
        if (!isset($have[strtolower($url)])) {
            $rows[] = ['key' => 'url', 'value' => $url];
        }
    }
    return $rows;
}

function xray_dnstap_with_default_proxy_urls(array $rows): array
{
    return xray_dnstap_with_default_urls($rows, XRAY_DNSTAP_DEFAULT_PROXY_URLS);
}

function xray_dnstap_with_default_geodat_urls(array $rows): array
{
    return xray_dnstap_with_default_urls($rows, XRAY_DNSTAP_DEFAULT_GEODAT_URLS);
}

function xray_dnstap_fetch_lists_on_start(): void
{
    if (function_exists('xray_dnstap_refresh_from_urls')) {
        $result = xray_dnstap_refresh_from_urls();
        if (empty($result['has_urls']) && empty($result['wrote'])) {
            echo "dnstap: no domain list URLs — skip download\n";
            return;
        }
        echo "dnstap: fetched " . (int)($result['fetched'] ?? 0) . " URL(s), "
            . (int)($result['proxy'] ?? $result['blocked'] ?? 0) . " PROXY + "
            . (int)($result['direct'] ?? $result['unblocked'] ?? 0) . " DIRECT + "
            . (int)($result['block'] ?? 0) . " BLOCK (lowercase, unique)\n";
        $failed = $result['failed'] ?? [];
        if (is_array($failed) && $failed !== []) {
            echo "dnstap: could not download: " . implode(' ', $failed) . "\n";
        }
        return;
    }
    $script = __DIR__ . '/xray-dnstap-conf.php';
    if (!is_readable($script)) {
        echo "dnstap: skip URL fetch — xray-dnstap-conf.php missing\n";
        return;
    }
    $php = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
    exec($php . ' ' . escapeshellarg($script) . ' fetch 2>&1', $out);
    foreach ($out as $line) {
        echo $line . "\n";
    }
}

function xray_dnstap_unbound_activate(): void
{
    exec('/usr/sbin/service dnstap_bgp stop 2>&1');

    if (!is_dir(XRAY_DNSTAP_SOCK_DIR) && !@mkdir(XRAY_DNSTAP_SOCK_DIR, 0755, true)) {
        echo "dnstap: cannot mkdir " . XRAY_DNSTAP_SOCK_DIR . "\n";
    } else {
        exec('chown unbound:unbound ' . escapeshellarg(XRAY_DNSTAP_SOCK_DIR) . ' 2>&1');
        echo "dnstap: " . XRAY_DNSTAP_SOCK_DIR . " unbound:unbound\n";
    }

    xray_dnstap_write_unbound_dnstap(true);
    xray_dnstap_write_bgp_listen();
    xray_dnstap_ensure_domain_files();
    xray_dnstap_fetch_lists_on_start();
    $proxyList  = '/usr/local/etc/dnstap-bgp/proxy.txt';
    $directList = '/usr/local/etc/dnstap-bgp/direct.txt';
    $blockList  = '/usr/local/etc/dnstap-bgp/block.txt';
    $as = xray_dnstap_bird_local_as();
    if (is_readable(XRAY_DNSTAP_BGP_CONF)) {
        $text = (string)file_get_contents(XRAY_DNSTAP_BGP_CONF);
        $text = xray_dnstap_toml_upsert_root($text, [
            'domains'        => $proxyList,
            'proxy_domains'  => $proxyList,
            'direct_domains' => $directList,
            'block_domains'  => $blockList,
        ]);
        $text = xray_dnstap_toml_upsert_section($text, 'bgp', ['as' => (string)$as]);
        @file_put_contents(XRAY_DNSTAP_BGP_CONF, $text);
        echo "dnstap: bgp.as={$as} (BIRD local AS)\n";
        echo "dnstap: proxy_domains={$proxyList} direct_domains={$directList} block_domains={$blockList}\n";
    }
    xray_dnstap_sync_rc_from_bgp();
    xray_unbound_reload();
    exec('/usr/sbin/service dnstap_bgp start 2>&1');
    echo "dnstap: dnstap_bgp start (Unbound already reloaded)\n";
    xray_dnstap_sync_bird(true);
}

function xray_dnstap_unbound_deactivate(): void
{
    exec('/usr/sbin/service dnstap_bgp stop 2>&1');
    echo "dnstap: dnstap_bgp stop\n";

    xray_dnstap_write_unbound_dnstap(false);
    xray_unbound_reload();
    xray_dnstap_sync_bird(false);
}
