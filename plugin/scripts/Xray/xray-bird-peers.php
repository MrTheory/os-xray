<?php
/**
 * Parse bgp.conf templates and generate per-peer BIRD includes.
 */

if (!defined('XRAY_BGP_CONF')) {
    define('XRAY_BGP_CONF', '/usr/local/etc/bird/bgp.conf');
}
if (!defined('XRAY_BIRD_INC_DIR')) {
    define('XRAY_BIRD_INC_DIR', '/usr/local/etc/bird');
}
if (!defined('XRAY_BIRD_PEERS_INC')) {
    define('XRAY_BIRD_PEERS_INC', '/usr/local/etc/bird/bgp.conf');
}
if (!defined('XRAY_BIRD_GENERATED_LIST')) {
    define('XRAY_BIRD_GENERATED_LIST', '/usr/local/etc/bird/.xray-bgp-generated');
}

function xray_bgp_flag_enabled($value): bool
{
    $v = strtolower(trim((string)$value));
    return $v === '1' || $v === 'on' || $v === 'true' || $v === 'yes';
}

function xray_bird_active_tun_if(string $family = 'ipv4'): string
{
    $family = strtolower($family);
    if ($family === 'ipv6' || $family === 'inet6') {
        $file = XRAY_BIRD_INC_DIR . '/active_tun_v6.inc';
        $def  = 'ACTIVE_TUN6_IF';
    } else {
        $file = XRAY_BIRD_INC_DIR . '/active_tun_v4.inc';
        $def  = 'ACTIVE_TUN4_IF';
    }
    if (!is_readable($file)) {
        return '';
    }
    $text = (string)file_get_contents($file);
    if (preg_match('/define\s+' . preg_quote($def, '/') . '\s*=\s*"([^"]+)"/', $text, $m)) {
        return $m[1];
    }
    return '';
}

function xray_bgp_template_names(): array
{
    return ['refilter', 'antifilter_download', 'antifilter_network'];
}

/**
 * @return array{gw:string,if:string}
 */
function xray_route_default_info(string $family = 'inet'): array
{
    $flag = ($family === 'inet6') ? '-inet6' : '-inet';
    $out  = [];
    exec('/sbin/route -n get ' . $flag . ' default 2>/dev/null', $out);
    $gw = '';
    $if = '';
    foreach ($out as $line) {
        if (preg_match('/^\s*gateway:\s+(\S+)/', $line, $m)) {
            $gw = explode('%', $m[1])[0];
        }
        if (preg_match('/^\s*interface:\s+(\S+)/', $line, $m)) {
            $if = $m[1];
        }
    }
    return ['gw' => $gw, 'if' => $if];
}

function xray_route_default_if(string $family = 'inet'): string
{
    return xray_route_default_info($family)['if'];
}

function xray_is_gateway_ip(string $ip, bool $v6): bool
{
    $ip = explode('%', $ip)[0];
    if ($ip === '') {
        return false;
    }
    if ($v6) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
        return $ip !== '::1' && $ip !== '::';
    }
    return xray_is_ipv4_addr($ip);
}

/**
 * FreeBSD device names of Xray instance TUNs (must not be treated as WAN).
 *
 * @return array<string,string> lowercase => original
 */
function xray_instance_tun_devices(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = [];
    try {
        if (!class_exists('OPNsense\\Core\\Config')) {
            return $cached;
        }
        $ins = \OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->instances ?? null;
        if ($ins === null || !isset($ins->instance)) {
            return $cached;
        }
        foreach ($ins->instance as $inst) {
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun !== '') {
                $cached[strtolower($tun)] = $tun;
            }
        }
    } catch (\Throwable $e) {
    }
    return $cached;
}

function xray_is_instance_tun_device(string $if): bool
{
    $if = trim($if);
    if ($if === '') {
        return false;
    }
    return isset(xray_instance_tun_devices()[strtolower($if)]);
}

/**
 * Logical OPNsense iface (wan/optN) for configured WAN4/WAN6 FreeBSD device.
 */
function xray_wan_logical_iface(bool $v6 = false): string
{
    $dev = $v6
        ? trim((string)xray_bird_general_device('bgp_if_wan6', ''))
        : trim((string)xray_bird_general_device('bgp_if_wan4', ''));
    if ($dev === '') {
        $dev = $v6
            ? (function_exists('xray_bird_wan6_dev') ? xray_bird_wan6_dev() : '')
            : (function_exists('xray_bird_wan4_dev') ? xray_bird_wan4_dev() : '');
    }
    if ($dev !== '' && function_exists('xray_gateways_logical_if_for_device')) {
        $logical = xray_gateways_logical_if_for_device($dev);
        if ($logical !== '') {
            return $logical;
        }
    }
    return 'wan';
}

/**
 * OPNsense /tmp/{iface}_router[v6] — per-interface upstream, not system default.
 */
function xray_wan_gateway_from_tmp(string $logical, bool $v6): string
{
    $logical = trim($logical);
    if ($logical === '') {
        return '';
    }
    $file = $v6 ? ('/tmp/' . $logical . '_routerv6') : ('/tmp/' . $logical . '_router');
    if (!is_readable($file)) {
        return '';
    }
    $ip = explode('%', trim((string)@file_get_contents($file)))[0];
    return xray_is_gateway_ip($ip, $v6) ? $ip : '';
}

/**
 * WAN upstream IPv4 for IPv4_GATEWAY / DIRECT — never system default via instance TUN.
 * (BLOCK→PROXY→DIRECT makes default go through PROXY; that must not become IPv4_GATEWAY.)
 */
function xray_wan_default_gateway4(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = '';
    $logical = xray_wan_logical_iface(false);
    $wantDev = trim((string)xray_bird_general_device('bgp_if_wan4', ''));
    if ($wantDev === '' && function_exists('xray_bird_wan4_dev')) {
        $wantDev = xray_bird_wan4_dev();
    }

    // 1) OPNsense interface gateway for WAN logical (not global default route)
    if (function_exists('get_interface_gateway')) {
        foreach (array_unique([$logical, 'wan']) as $iface) {
            if ($iface === '') {
                continue;
            }
            try {
                $ip = trim((string)get_interface_gateway($iface));
                if (xray_is_gateway_ip($ip, false)) {
                    $cached = $ip;
                    return $cached;
                }
            } catch (\Throwable $e) {
            }
        }
    }
    if (class_exists('\\OPNsense\\Routing\\Gateways')) {
        try {
            $gws = new \OPNsense\Routing\Gateways();
            $ip = trim((string)$gws->getInterfaceGateway($logical, 'inet'));
            if (xray_is_gateway_ip($ip, false)) {
                $cached = $ip;
                return $cached;
            }
        } catch (\Throwable $e) {
        }
    }

    // 2) Per-interface DHCP/PPPoE router file
    $tmp = xray_wan_gateway_from_tmp($logical, false);
    if ($tmp !== '') {
        $cached = $tmp;
        return $cached;
    }
    if ($logical !== 'wan') {
        $tmp = xray_wan_gateway_from_tmp('wan', false);
        if ($tmp !== '') {
            $cached = $tmp;
            return $cached;
        }
    }

    // 3) System default ONLY if it exits via WAN4 device (never via instance TUN)
    $info = xray_route_default_info('inet');
    if (xray_is_gateway_ip($info['gw'], false)
        && $info['if'] !== ''
        && !xray_is_instance_tun_device($info['if'])
        && $wantDev !== ''
        && strcasecmp($info['if'], $wantDev) === 0) {
        $cached = $info['gw'];
        return $cached;
    }

    return $cached;
}

/**
 * IPv6 WAN upstream for IPv6_GATEWAY / filters (address only, no %scope).
 * Same rule as IPv4: never take system default when it is via instance TUN.
 */
function xray_wan_default_gateway6(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = '';
    $logical = xray_wan_logical_iface(true);
    $wantDev = trim((string)xray_bird_general_device('bgp_if_wan6', ''));
    if ($wantDev === '') {
        $wantDev = trim((string)xray_bird_general_device('bgp_if_wan4', ''));
    }
    if ($wantDev === '' && function_exists('xray_bird_wan6_dev')) {
        $wantDev = xray_bird_wan6_dev();
    }

    if (function_exists('get_interface_gatewayv6')) {
        foreach (array_unique([$logical, 'wan']) as $iface) {
            if ($iface === '') {
                continue;
            }
            try {
                $ip = explode('%', trim((string)get_interface_gatewayv6($iface)))[0];
                if (xray_is_gateway_ip($ip, true)) {
                    $cached = $ip;
                    return $cached;
                }
            } catch (\Throwable $e) {
            }
        }
    }
    if (class_exists('\\OPNsense\\Routing\\Gateways')) {
        try {
            $gws = new \OPNsense\Routing\Gateways();
            $ip = explode('%', trim((string)$gws->getInterfaceGateway($logical, 'inet6')))[0];
            if (xray_is_gateway_ip($ip, true)) {
                $cached = $ip;
                return $cached;
            }
        } catch (\Throwable $e) {
        }
    }

    $tmp = xray_wan_gateway_from_tmp($logical, true);
    if ($tmp !== '') {
        $cached = $tmp;
        return $cached;
    }
    if ($logical !== 'wan') {
        $tmp = xray_wan_gateway_from_tmp('wan', true);
        if ($tmp !== '') {
            $cached = $tmp;
            return $cached;
        }
    }

    $info = xray_route_default_info('inet6');
    if (xray_is_gateway_ip($info['gw'], true)
        && $info['if'] !== ''
        && !xray_is_instance_tun_device($info['if'])
        && $wantDev !== ''
        && strcasecmp($info['if'], $wantDev) === 0) {
        $cached = $info['gw'];
        return $cached;
    }

    return $cached;
}

function xray_wan_devices(): array
{
    $devs = [];
    if (function_exists('get_real_interface')) {
        try {
            $v4 = trim((string)get_real_interface('wan'));
            $v6 = trim((string)get_real_interface('wan', 'inet6'));
            if ($v4 !== '') {
                $devs[] = $v4;
            }
            if ($v6 !== '' && $v6 !== $v4) {
                $devs[] = $v6;
            }
        } catch (\Throwable $e) {
        }
    }
    try {
        if (class_exists('OPNsense\\Core\\Config')) {
            $cfg = \OPNsense\Core\Config::getInstance()->object();
            $wan = $cfg->interfaces->wan ?? null;
            if ($wan) {
                $if = trim((string)($wan->if ?? ''));
                if ($if !== '') {
                    $devs[] = $if;
                }
            }
        }
    } catch (\Throwable $e) {
    }
    foreach (['inet', 'inet6'] as $fam) {
        $rif = xray_route_default_if($fam);
        if ($rif !== '') {
            $devs[] = $rif;
        }
    }
    $uniq = [];
    foreach ($devs as $d) {
        if ($d !== '' && !isset($uniq[$d])) {
            $uniq[$d] = true;
        }
    }
    return array_keys($uniq);
}

function xray_is_ipv4_addr(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && $ip !== '0.0.0.0'
        && strncmp($ip, '127.', 4) !== 0;
}

function xray_is_ipv6_addr(string $ip): bool
{
    $ip = explode('%', $ip)[0];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return false;
    }
    if ($ip === '::1' || $ip === '::' || strncasecmp($ip, 'fe80:', 5) === 0) {
        return false;
    }
    return true;
}

function xray_ifconfig_wan_addrs(string $if): array
{
    $v4 = '';
    $v6 = '';
    if ($if === '') {
        return ['', ''];
    }
    $out = [];
    exec('/sbin/ifconfig ' . escapeshellarg($if) . ' 2>/dev/null', $out);
    foreach ($out as $line) {
        if (stripos($line, 'vhid') !== false) {
            continue;
        }
        if ($v4 === '' && preg_match('/\binet\s+(\d+\.\d+\.\d+\.\d+)/', $line, $m) && xray_is_ipv4_addr($m[1])) {
            $v4 = $m[1];
        }
        if ($v6 === '' && preg_match('/\binet6\s+([0-9a-fA-F:]+)/', $line, $m) && xray_is_ipv6_addr($m[1])) {
            $v6 = $m[1];
        }
    }
    return [$v4, $v6];
}

function xray_wan_ipv4(): string
{
    if (function_exists('interfaces_primary_address')) {
        try {
            $row = interfaces_primary_address('wan');
            $ip  = trim((string)($row[0] ?? ''));
            if (xray_is_ipv4_addr($ip)) {
                return $ip;
            }
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('get_interface_ip')) {
        try {
            foreach (array_merge(['wan'], xray_wan_devices()) as $key) {
                $ip = trim((string)get_interface_ip($key));
                if (xray_is_ipv4_addr($ip)) {
                    return $ip;
                }
            }
        } catch (\Throwable $e) {
        }
    }
    try {
        if (class_exists('OPNsense\\Core\\Config')) {
            $cfg = \OPNsense\Core\Config::getInstance()->object();
            $wan = $cfg->interfaces->wan ?? null;
            if ($wan) {
                $ip = trim((string)($wan->ipaddr ?? ''));
                if (xray_is_ipv4_addr($ip)) {
                    return $ip;
                }
            }
        }
    } catch (\Throwable $e) {
    }
    foreach (xray_wan_devices() as $if) {
        [$v4] = xray_ifconfig_wan_addrs($if);
        if ($v4 !== '') {
            return $v4;
        }
    }
    return '';
}

function xray_wan_ipv6(): string
{
    if (function_exists('interfaces_primary_address6')) {
        try {
            $row = interfaces_primary_address6('wan');
            $ip  = explode('%', trim((string)($row[0] ?? '')))[0];
            if (xray_is_ipv6_addr($ip)) {
                return $ip;
            }
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('get_interface_ipv6')) {
        try {
            foreach (array_merge(['wan'], xray_wan_devices()) as $key) {
                $ip = explode('%', trim((string)get_interface_ipv6($key)))[0];
                if (xray_is_ipv6_addr($ip)) {
                    return $ip;
                }
            }
        } catch (\Throwable $e) {
        }
    }
    try {
        if (class_exists('OPNsense\\Core\\Config')) {
            $cfg = \OPNsense\Core\Config::getInstance()->object();
            $wan = $cfg->interfaces->wan ?? null;
            if ($wan) {
                $ip = explode('%', trim((string)($wan->ipaddrv6 ?? '')))[0];
                if (xray_is_ipv6_addr($ip)) {
                    return $ip;
                }
            }
        }
    } catch (\Throwable $e) {
    }
    foreach (xray_wan_devices() as $if) {
        [, $v6] = xray_ifconfig_wan_addrs($if);
        if ($v6 !== '') {
            return $v6;
        }
    }
    return '';
}

function xray_source_for_peer(array $p): string
{
    $src = trim((string)($p['source_address'] ?? ''));
    $src = explode('%', $src)[0];
    if ($src !== '' && (xray_is_ipv4_addr($src) || xray_is_ipv6_addr($src))) {
        return $src;
    }
    $neighbor = explode('%', trim((string)($p['neighbor'] ?? '')))[0];
    $v6only   = (($p['ipv6'] ?? '0') === '1') && (($p['ipv4'] ?? '0') !== '1');
    if ($v6only || xray_is_ipv6_addr($neighbor)) {
        return xray_wan_ipv6();
    }
    // Hostname or IPv4 neighbor → WAN IPv4 source
    return xray_wan_ipv4();
}

function xray_bird_write_router_id(): void
{
    $dir = XRAY_BIRD_INC_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $v4   = xray_wan_ipv4();
    $file = $dir . '/router_id.inc';
    if ($v4 !== '') {
        file_put_contents($file, 'router id ' . $v4 . ";\n");
    } else {
        file_put_contents($file, "# router id: WAN IPv4 not found\n");
    }
    @chmod($file, 0644);
}

function xray_bird_pid_running(string $pidfile): bool
{
    if (!is_readable($pidfile)) {
        return false;
    }
    $pid = (int)trim((string)file_get_contents($pidfile));
    if ($pid <= 0) {
        return false;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

function xray_bird_general_device(string $key, string $default): string
{
    try {
        if (class_exists('OPNsense\\Core\\Config')) {
            $cfg = \OPNsense\Core\Config::getInstance()->object();
            $g   = $cfg->OPNsense->xray->general ?? null;
            $v   = trim((string)($g->{$key} ?? ''));
            if ($v !== '') {
                return xray_bird_sanitize_ifname($v, $default);
            }
        }
    } catch (\Throwable $e) {
    }
    return $default;
}

/**
 * Detect FreeBSD WAN device for IPv4.
 * Prefer OPNsense wan real iface; use default-route iface only if it is not an instance TUN.
 */
function xray_bird_detect_wan4_dev(): string
{
    if (function_exists('get_real_interface')) {
        try {
            $v4 = trim((string)get_real_interface('wan'));
            if ($v4 !== '' && !xray_is_instance_tun_device($v4)) {
                return xray_bird_sanitize_ifname($v4, '');
            }
        } catch (\Throwable $e) {
        }
    }
    $rif = xray_route_default_if('inet');
    if ($rif !== '' && !xray_is_instance_tun_device($rif)) {
        return xray_bird_sanitize_ifname($rif, '');
    }
    $devs = xray_wan_devices();
    foreach ($devs as $d) {
        if ($d !== '' && !xray_is_instance_tun_device($d)) {
            return xray_bird_sanitize_ifname($d, '');
        }
    }
    return '';
}

/**
 * Detect FreeBSD WAN device for IPv6 (skip instance TUN default routes).
 */
function xray_bird_detect_wan6_dev(): string
{
    if (function_exists('get_real_interface')) {
        try {
            $v6 = trim((string)get_real_interface('wan', 'inet6'));
            if ($v6 !== '' && !xray_is_instance_tun_device($v6)) {
                return xray_bird_sanitize_ifname($v6, '');
            }
            $v4 = trim((string)get_real_interface('wan'));
            if ($v4 !== '' && !xray_is_instance_tun_device($v4)) {
                return xray_bird_sanitize_ifname($v4, '');
            }
        } catch (\Throwable $e) {
        }
    }
    $rif = xray_route_default_if('inet6');
    if ($rif !== '' && !xray_is_instance_tun_device($rif)) {
        return xray_bird_sanitize_ifname($rif, '');
    }
    return xray_bird_detect_wan4_dev();
}

function xray_bird_wan4_dev(): string
{
    $v = xray_bird_general_device('bgp_if_wan4', '');
    return $v !== '' ? $v : xray_bird_detect_wan4_dev();
}

function xray_bird_wan6_dev(): string
{
    $v = xray_bird_general_device('bgp_if_wan6', '');
    return $v !== '' ? $v : xray_bird_detect_wan6_dev();
}

/**
 * First-install seed: write detected WAN devices into General if fields are empty.
 *
 * @return array{wan4:string,wan6:string,saved:bool}
 */
function xray_bird_seed_wan_if_config(): array
{
    $out = ['wan4' => '', 'wan6' => '', 'saved' => false];
    try {
        if (!class_exists('OPNsense\\Core\\Config')) {
            return $out;
        }
        $cfg  = \OPNsense\Core\Config::getInstance();
        $obj  = $cfg->object();
        if (!isset($obj->OPNsense)) {
            $obj->addChild('OPNsense');
        }
        if (!isset($obj->OPNsense->xray)) {
            $obj->OPNsense->addChild('xray');
        }
        $xray = $obj->OPNsense->xray;
        if (!isset($xray->general)) {
            $xray->addChild('general');
        }
        $g = $xray->general;

        $d4 = xray_bird_detect_wan4_dev();
        $d6 = xray_bird_detect_wan6_dev();
        if ($d6 === '' && $d4 !== '') {
            $d6 = $d4;
        }
        $changed = false;

        $cur4 = trim((string)($g->bgp_if_wan4 ?? ''));
        if ($cur4 === '' && $d4 !== '') {
            if (!isset($g->bgp_if_wan4)) {
                $g->addChild('bgp_if_wan4', $d4);
            } else {
                $g->bgp_if_wan4 = $d4;
            }
            $changed = true;
            $cur4 = $d4;
        }

        $cur6 = trim((string)($g->bgp_if_wan6 ?? ''));
        if ($cur6 === '' && $d6 !== '') {
            if (!isset($g->bgp_if_wan6)) {
                $g->addChild('bgp_if_wan6', $d6);
            } else {
                $g->bgp_if_wan6 = $d6;
            }
            $changed = true;
            $cur6 = $d6;
        }

        $out['wan4'] = $cur4 !== '' ? $cur4 : $d4;
        $out['wan6'] = $cur6 !== '' ? $cur6 : $d6;
        if ($changed) {
            $cfg->save();
            $out['saved'] = true;
        }
    } catch (\Throwable $e) {
    }
    return $out;
}

function xray_bird_active_tun_ifaces(): array
{
    $names = [];
    foreach ([xray_bird_wan4_dev(), xray_bird_wan6_dev(), 'lo0'] as $if) {
        if ($if !== '') {
            $names[$if] = true;
        }
    }
    // BIRD protocol direct: loopback as "lo" (FreeBSD device is lo0).
    $names['lo'] = true;
    try {
        if (!class_exists('OPNsense\\Core\\Config')) {
            return array_keys($names);
        }
        $cfg    = \OPNsense\Core\Config::getInstance()->object();
        $g      = $cfg->OPNsense->xray->general ?? null;
        $ins    = $cfg->OPNsense->xray->instances ?? null;
        $plugin = (string)($g->enabled ?? '0') === '1';
        if (!$plugin || !$ins) {
            return array_keys($names);
        }
        foreach ($ins->instance as $inst) {
            $uuid = (string)$inst['uuid'];
            if ($uuid === '' || (string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            if (!xray_bird_pid_running('/var/run/tun2socks_' . $uuid . '.pid')) {
                continue;
            }
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                $tun = 'proxytun2socks0';
            }
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,14}$/', $tun) !== 1) {
                continue;
            }
            $names[$tun] = true;
        }
    } catch (\Throwable $e) {
    }
    return array_keys($names);
}

function xray_bird_write_direct_ifs(): void
{
    $dir = XRAY_BIRD_INC_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $quoted = [];
    foreach (xray_bird_active_tun_ifaces() as $if) {
        $quoted[] = '"' . $if . '"';
    }
    $body = "# generated by os-xray — protocol direct interfaces (WAN4/WAN6, lo, tun2socks)\n"
        . 'interface ' . implode(', ', $quoted) . ";\n";
    $file = $dir . '/direct_ifs.inc';
    file_put_contents($file, $body);
    @chmod($file, 0644);
}

function xray_bird_apply_wan_addresses(): void
{
    xray_bird_write_router_id();
    xray_bird_write_wan_if();
    xray_bird_write_direct_ifs();
    xray_bird_fill_config_source_addresses();
}

function xray_bird_fill_config_source_addresses(): void
{
    try {
        if (!class_exists('OPNsense\\Core\\Config')) {
            return;
        }
        $cfg  = \OPNsense\Core\Config::getInstance();
        $obj  = $cfg->object();
        $xray = $obj->OPNsense->xray ?? null;
        if (!$xray || !isset($xray->bgppeers->peer)) {
            return;
        }
        $changed = false;
        foreach ($xray->bgppeers->peer as $peer) {
            $cur = trim((string)($peer->source_address ?? ''));
            if ($cur !== '' && (xray_is_ipv4_addr($cur) || xray_is_ipv6_addr($cur))) {
                continue;
            }
            $src = xray_source_for_peer([
                'neighbor'       => (string)($peer->neighbor ?? ''),
                'source_address' => '',
                'ipv4'           => ((string)($peer->ipv4 ?? '0') === '1') ? '1' : '0',
                'ipv6'           => ((string)($peer->ipv6 ?? '0') === '1') ? '1' : '0',
            ]);
            if ($src === '') {
                continue;
            }
            $peer->source_address = $src;
            $changed = true;
        }
        if ($changed) {
            $cfg->save();
        }
    } catch (\Throwable $e) {
    }
}

function xray_bgp_read_with_includes(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }
    $out = '';
    foreach (explode("\n", (string)file_get_contents($path)) as $line) {
        if (preg_match('/^\s*include\s+"([^"]+)";/', $line, $m)) {
            $inc = $m[1];
            if (is_readable($inc)) {
                $out .= (string)file_get_contents($inc) . "\n";
            }
            continue;
        }
        $out .= $line . "\n";
    }
    return $out;
}

function xray_bird_extract_block(string $text, int $openBracePos): ?string
{
    $len   = strlen($text);
    $depth = 0;
    for ($i = $openBracePos; $i < $len; $i++) {
        $ch = $text[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($text, $openBracePos + 1, $i - $openBracePos - 1);
            }
        }
    }
    return null;
}

function xray_bird_parse_impexp(string $block, string $family): array
{
    $import = 'none';
    $export = 'none';
    $enabled = false;
    if (!preg_match('/\b' . preg_quote($family, '/') . '\s*\{/i', $block, $m, PREG_OFFSET_CAPTURE)) {
        return ['enabled' => false, 'import' => $import, 'export' => $export];
    }
    $pos  = strpos($block, '{', $m[0][1]);
    $body = $pos === false ? '' : (string)xray_bird_extract_block($block, $pos);
    $enabled = true;
    if (preg_match('/\bimport\s+filter\s+([A-Za-z_][A-Za-z0-9_]*)/i', $body, $mm)) {
        $import = $mm[1];
    } elseif (preg_match('/\bimport\s+(none|all)\b/i', $body, $mm)) {
        $import = strtolower($mm[1]);
    }
    if (preg_match('/\bexport\s+filter\s+([A-Za-z_][A-Za-z0-9_]*)/i', $body, $mm)) {
        $export = $mm[1];
    } elseif (preg_match('/\bexport\s+(none|all)\b/i', $body, $mm)) {
        $export = strtolower($mm[1]);
    }
    return ['enabled' => $enabled, 'import' => $import, 'export' => $export];
}

function xray_parse_bgp_conf_protocols(string $text): array
{
    $text = preg_replace('/^\s*#.*$/m', '', $text);
    $peers = [];
    if (!preg_match_all('/protocol\s+bgp\s+([A-Za-z_][A-Za-z0-9_]*)\s*\{/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($m[1] as $i => $nameCap) {
        $open = strpos($text, '{', $m[0][$i][1]);
        if ($open === false) {
            continue;
        }
        $body = xray_bird_extract_block($text, $open);
        if ($body === null) {
            continue;
        }
        $v4 = xray_bird_parse_impexp($body, 'ipv4');
        $v6 = xray_bird_parse_impexp($body, 'ipv6');
        $localAs    = preg_match('/\blocal\s+as\s+(\d+)/i', $body, $mm) ? $mm[1] : '65103';
        $neighbor   = '';
        $neighborAs = '65412';
        if (preg_match('/\bneighbor\s+([0-9a-fA-F.:]+)\s+as\s+(\d+)/i', $body, $mm)) {
            $neighbor   = $mm[1];
            $neighborAs = $mm[2];
        }
        $source = '';
        if (preg_match('/\bsource\s+address\s+([0-9a-fA-F.:]+)/i', $body, $mm)) {
            $source = $mm[1];
        }
        $hold = preg_match('/\bhold\s+time\s+(\d+)/i', $body, $mm) ? $mm[1] : '240';
        $peers[] = [
            'enabled'        => '1',
            'name'           => $nameCap[0],
            'local_as'       => $localAs,
            'neighbor'       => $neighbor,
            'neighbor_as'    => $neighborAs,
            'source_address' => $source,
            'ipv4'           => $v4['enabled'] ? '1' : '0',
            'ipv4_import'    => $v4['import'],
            'ipv4_export'    => $v4['export'],
            'ipv6'           => $v6['enabled'] ? '1' : '0',
            'ipv6_import'    => $v6['import'],
            'ipv6_export'    => $v6['export'],
            'multihop'       => preg_match('/\bmultihop\b/i', $body) ? '1' : '0',
            'hold_time'      => $hold,
        ];
    }
    return $peers;
}

function xray_bgp_conf_default_peer(): array
{
    $text = '';
    foreach (xray_bgp_template_names() as $name) {
        $path = XRAY_BIRD_INC_DIR . '/' . xray_bird_inc_filename($name);
        if (!is_readable($path)) {
            $path = XRAY_BIRD_INC_DIR . '/' . $name . '.inc';
        }
        if (is_readable($path)) {
            $text .= (string)file_get_contents($path) . "\n";
        }
    }
    if ($text === '') {
        $text = xray_bgp_read_with_includes(XRAY_BGP_CONF);
    }
    $peers = xray_parse_bgp_conf_protocols($text);
    return $peers[0] ?? [];
}

function xray_bgp_community_ident(string $name): string
{
    $name = basename(trim($name));
    $name = preg_replace('/\.inc$/i', '', $name);
    $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    $name = trim($name, '_');
    if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
        return '';
    }
    return $name;
}

function xray_bgp_parse_communities(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $raw = preg_replace('/(\d+)\s*,\s*:/', '$1:', $raw);
    $pairs = [];
    if (preg_match_all('/\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $pairs[] = [(int)$row[1], (int)$row[2]];
        }
        return $pairs;
    }
    $tokens = preg_split('/\s*,\s*/', $raw);
    $buf    = [];
    foreach ($tokens as $t) {
        $t = trim((string)$t);
        if ($t === '') {
            continue;
        }
        if (preg_match('/^(\d+)\s*:\s*(\d+)$/', $t, $mm)) {
            $pairs[] = [(int)$mm[1], (int)$mm[2]];
            continue;
        }
        if (preg_match('/^\d+$/', $t)) {
            $buf[] = (int)$t;
            if (count($buf) >= 2) {
                $pairs[] = [$buf[0], $buf[1]];
                $buf     = [];
            }
        }
    }
    return $pairs;
}

function xray_bgp_format_communities_inc(array $pairs): string
{
    $parts = [];
    foreach ($pairs as $pair) {
        $parts[] = '(' . (int)$pair[0] . ', ' . (int)$pair[1] . ')';
    }
    return implode(', ', $parts);
}

function xray_bgp_format_communities_gui(array $pairs): string
{
    $parts = [];
    foreach ($pairs as $pair) {
        $parts[] = (int)$pair[0] . ':' . (int)$pair[1];
    }
    return implode(', ', $parts);
}

function xray_bird_community_ident(string $name): string
{
    $ident = xray_bgp_community_ident($name);
    if ($ident === '') {
        return '';
    }
    if (strncasecmp($ident, 'community_', 10) !== 0) {
        $ident = 'community_' . $ident;
    }
    return $ident;
}

function xray_bird_community_filename(string $ident): string
{
    $ident = xray_bird_community_ident($ident);
    if ($ident === '') {
        return '';
    }
    return $ident . '.inc';
}

function xray_bird_filter_filename(string $ident): string
{
    $ident = xray_bgp_community_ident($ident);
    if (strncasecmp($ident, 'accept_', 7) === 0) {
        $ident = 'filter_' . substr($ident, 7);
    } elseif (strncasecmp($ident, 'filter_', 7) !== 0) {
        $ident = 'filter_' . $ident;
    }
    return $ident . '.inc';
}

function xray_bird_filter_ident(string $name): string
{
    return basename(xray_bird_filter_filename($name), '.inc');
}

function xray_bird_write_community_file(string $dir, string $ident, array $pairs): string
{
    $ident = xray_bird_community_ident($ident);
    if ($ident === '') {
        return '';
    }
    $file = $dir . '/' . $ident . '.inc';
    $body = 'define ' . $ident . ' = [ ' . xray_bgp_format_communities_inc($pairs) . ' ];' . "\n";
    file_put_contents($file, $body);
    @chmod($file, 0644);
    return $file;
}

function xray_get_bgp_communities_from_config(): array
{
    try {
        $cfg  = \OPNsense\Core\Config::getInstance()->object();
        $node = $cfg->OPNsense->xray->bgpcommunities ?? null;
        if (!$node || !isset($node->community)) {
            return [];
        }
        $result = [];
        foreach ($node->community as $item) {
            $uuid = (string)$item['uuid'];
            if ($uuid === '') {
                continue;
            }
            $result[$uuid] = [
                'enabled'     => xray_bgp_flag_enabled($item->enabled ?? '1') ? '1' : '0',
                'name'        => (string)($item->name ?? ''),
                'communities' => (string)($item->communities ?? ''),
            ];
        }
        return $result;
    } catch (\Throwable $e) {
        return [];
    }
}

function xray_get_bgp_filters_from_config(): array
{
    try {
        $cfg  = \OPNsense\Core\Config::getInstance()->object();
        $node = $cfg->OPNsense->xray->bgpfilters ?? null;
        if (!$node || !isset($node->filter)) {
            return [];
        }
        $result = [];
        foreach ($node->filter as $item) {
            $uuid = (string)$item['uuid'];
            if ($uuid === '') {
                continue;
            }
            $result[$uuid] = [
                'enabled'        => xray_bgp_flag_enabled($item->enabled ?? '1') ? '1' : '0',
                'name'           => (string)($item->name ?? ''),
                'community'      => (string)($item->community ?? ''),
                'family'         => (string)($item->family ?? 'ipv4'),
                'tun_if'         => (string)($item->tun_if ?? ''),
                'blocks'         => (string)($item->blocks ?? ''),
            ];
        }
        return $result;
    } catch (\Throwable $e) {
        return [];
    }
}

function xray_resolve_community_define(string $value): string
{
    $value = trim($value);
    if ($value === '' || strcasecmp($value, 'none') === 0) {
        return '';
    }
    static $byUuid = null;
    if ($byUuid === null) {
        $byUuid = [];
        foreach (xray_get_bgp_communities_from_config() as $uuid => $c) {
            $ident = xray_bird_community_ident((string)($c['name'] ?? ''));
            if ($ident !== '') {
                $byUuid[$uuid] = $ident;
            }
        }
    }
    if (isset($byUuid[$value])) {
        return $byUuid[$value];
    }
    if (strncasecmp($value, 'community_', 10) === 0) {
        $rest = substr($value, 10);
        if (isset($byUuid[$rest])) {
            return $byUuid[$rest];
        }
    }
    return xray_bird_community_ident($value);
}

function xray_bird_filter_ifname(string $tun, string $fam): string
{
    $tun = trim($tun);
    $low = strtolower($tun);
    if ($low === 'wan6' || $low === 'wan6_if' || strcasecmp($tun, 'WAN6_IF') === 0) {
        return 'WAN6_IF';
    }
    if ($low === 'wan' || $low === 'wan_if' || $low === 'wan4' || $low === 'wan4_if'
        || strcasecmp($tun, 'WAN4_IF') === 0 || strcasecmp($tun, 'WAN_IF') === 0) {
        return ($fam === 'ipv6' && ($low === 'wan' || $low === 'wan_if')) ? 'WAN6_IF' : 'WAN4_IF';
    }
    if ($low === 'null' || $low === 'null4' || $low === 'null6'
        || $low === 'null4_if' || $low === 'null6_if' || $low === 'null_if'
        || strcasecmp($tun, 'NULL4_IF') === 0 || strcasecmp($tun, 'NULL6_IF') === 0
        || strcasecmp($tun, 'NULL_IF') === 0) {
        return 'NULL';
    }
    if (strcasecmp($tun, 'ACTIVE_TUN6_IF') === 0) {
        return 'ACTIVE_TUN6_IF';
    }
    if (strcasecmp($tun, 'ACTIVE_TUN4_IF') === 0) {
        return 'ACTIVE_TUN4_IF';
    }
    return ($fam === 'ipv6') ? 'ACTIVE_TUN6_IF' : 'ACTIVE_TUN4_IF';
}

function xray_bird_sanitize_ifname(string $if, string $fallback = ''): string
{
    $if = preg_replace('/[^A-Za-z0-9._-]/', '', $if) ?? '';
    return $if !== '' ? $if : $fallback;
}

/**
 * Tail for IPv6 static DIRECT routes.
 * BIRD static uses "via …", not "device …" (DEVICE is a protocol keyword → syntax error).
 * Prefer: via fe80::x%iface | via global | via "iface".
 */
function xray_bird_ipv6_direct_via_clause(): string
{
    $if = xray_bird_wan6_dev();
    if ($if === '' || xray_is_instance_tun_device($if)) {
        $rif = xray_route_default_info('inet6')['if'] ?? '';
        if ($rif !== '' && !xray_is_instance_tun_device($rif)) {
            $if = $rif;
        } else {
            $if = '';
        }
    }
    $if = xray_bird_sanitize_ifname($if, '');
    $gw = xray_wan_default_gateway6();

    if ($gw !== '') {
        if (stripos($gw, 'fe80:') === 0) {
            if ($if !== '') {
                // %scope only in via literal (not in define)
                return 'via ' . $gw . '%' . $if . ';';
            }
            return 'via WAN6_IF;';
        }
        return 'via ' . $gw . ';';
    }
    if ($if !== '') {
        return 'via "' . $if . '";';
    }
    return 'via WAN6_IF;';
}

function xray_bird_write_wan_if(): void
{
    $dir = XRAY_BIRD_INC_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $wan4  = xray_bird_wan4_dev();
    $wan6  = xray_bird_wan6_dev();
    $gw4 = xray_wan_default_gateway4();
    $gw6 = xray_wan_default_gateway6();
    $body = "# generated by os-xray — WAN4/WAN6 devices (General tab)\n";
    if ($wan4 !== '') {
        $body .= 'define WAN4_IF = "' . $wan4 . '";' . "\n"
            . 'define WAN_IF = "' . $wan4 . '";' . "\n";
    } else {
        $body .= "# WAN4_IF / WAN_IF: not set (General → Interface WAN4)\n";
    }
    if ($wan6 !== '') {
        $body .= 'define WAN6_IF = "' . $wan6 . '";' . "\n";
    } else {
        $body .= "# WAN6_IF: not set (General → Interface WAN6)\n";
    }
    if ($gw4 !== '') {
        $body .= 'define IPv4_GATEWAY = ' . $gw4 . ";\n";
    } else {
        $body .= "# IPv4_GATEWAY: WAN default gateway not found\n";
    }
    if ($gw6 !== '') {
        $body .= 'define IPv6_GATEWAY = ' . $gw6 . ";\n";
    } else {
        $body .= "# IPv6_GATEWAY: WAN default gateway not found\n";
    }
    $file = $dir . '/wan_if.inc';
    @file_put_contents($file, $body);
    @chmod($file, 0644);
    xray_bird_ensure_wan_if_include();
}

function xray_bird_ensure_wan_if_include(): void
{
    $conf = '/usr/local/etc/bird.conf';
    if (!is_readable($conf)) {
        return;
    }
    $text = (string)file_get_contents($conf);
    if (strpos($text, 'wan_if.inc') !== false) {
        return;
    }
    $line = 'include "/usr/local/etc/bird/wan_if.inc";';
    $needle = 'include "/usr/local/etc/bird/active_tun_v6.inc";';
    if (strpos($text, $needle) !== false) {
        $text = str_replace($needle, $needle . "\n" . $line, $text);
    } else {
        $text = $line . "\n" . $text;
    }
    @file_put_contents($conf, $text);
}

function xray_bird_filter_gw_line(string $birdIf, string $pad): string
{
    if ($birdIf === 'WAN4_IF') {
        return xray_wan_default_gateway4() === '' ? '' : $pad . "gw = IPv4_GATEWAY;\n";
    }
    if ($birdIf === 'WAN6_IF') {
        return xray_wan_default_gateway6() === '' ? '' : $pad . "gw = IPv6_GATEWAY;\n";
    }
    return '';
}

function xray_bird_filter_then_lines(string $tun, string $pad): string
{
    if ($tun === 'NULL') {
        return $pad . "dest = RTD_BLACKHOLE;\n";
    }
    return $pad . 'ifname = ' . $tun . ";\n" . xray_bird_filter_gw_line($tun, $pad);
}

function xray_bird_filter_blocks(array $f): array
{
    if (class_exists('OPNsense\\Xray\\BgpFilter')
        && method_exists('OPNsense\\Xray\\BgpFilter', 'parseBlocks')) {
        return \OPNsense\Xray\BgpFilter::parseBlocks($f);
    }
    return [[
        'community' => (string)($f['community'] ?? ''),
        'tun_if'    => (string)($f['tun_if'] ?? ''),
    ]];
}

function xray_bird_render_filter(array $f): string
{
    $name = xray_bird_filter_ident((string)($f['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    $fam    = ((string)($f['family'] ?? 'ipv4') === 'ipv6') ? 'ipv6' : 'ipv4';
    $defNet = ($fam === 'ipv6') ? '::/0' : '0.0.0.0/0';

    $ifs = [];
    foreach (xray_bird_filter_blocks($f) as $b) {
        $comm = xray_resolve_community_define((string)($b['community'] ?? ''));
        if ($comm === '') {
            continue;
        }
        $tun = xray_bird_filter_ifname((string)($b['tun_if'] ?? ''), $fam);
        $ifs[] = '    if bgp_community ~ ' . $comm . " then {\n"
            . '       if net = ' . $defNet . " then reject;\n"
            . xray_bird_filter_then_lines($tun, '       ')
            . "       accept;\n"
            . '    }';
    }
    if ($ifs !== []) {
        return 'filter ' . $name . " {\n"
            . implode("\n", $ifs) . "\n    reject;\n}\n";
    }

    $tun = xray_bird_filter_ifname((string)($f['tun_if'] ?? ''), $fam);
    return 'filter ' . $name . " {\n"
        . '    if net = ' . $defNet . " then reject;\n"
        . xray_bird_filter_then_lines($tun, '    ')
        . "    accept;\n}\n";
}

function xray_bird_write_gui_communities(string $dir, array &$written, array &$commFiles): void
{
    $rows = xray_get_bgp_communities_from_config();
    foreach ($rows as $c) {
        $ident = xray_bird_community_ident((string)$c['name']);
        $pairs = xray_bgp_parse_communities((string)$c['communities']);
        if ($ident === '' || $pairs === []) {
            continue;
        }
        $file = xray_bird_write_community_file($dir, $ident, $pairs);
        if ($file === '') {
            continue;
        }
        $written[$file] = true;
        if (($c['enabled'] ?? '0') === '1') {
            $commFiles[$file] = true;
        }
    }
}

function xray_bird_write_gui_filters(string $dir, array &$written): void
{
    $filters = xray_get_bgp_filters_from_config();
    $inc     = ['# generated by os-xray — BGP import filters from config.xml', ''];
    foreach ($filters as $f) {
        $name = xray_bird_filter_ident((string)($f['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $file = $dir . '/' . xray_bird_filter_filename($name);
        $body = xray_bird_render_filter($f);
        if ($body === '') {
            continue;
        }
        file_put_contents($file, $body);
        @chmod($file, 0644);
        $written[$file] = true;
        if (($f['enabled'] ?? '0') === '1') {
            $inc[] = 'include "' . $file . '";';
        }
    }
    $inc[] = '';
    file_put_contents($dir . '/filters.inc', implode("\n", $inc));
    @chmod($dir . '/filters.inc', 0644);
}

function xray_resolve_import_filter(string $value): string
{
    $value = trim($value);
    if ($value === '' || strcasecmp($value, 'none') === 0) {
        return 'none';
    }
    if (strcasecmp($value, 'all') === 0) {
        return 'all';
    }
    static $byUuid = null;
    if ($byUuid === null) {
        $byUuid = [];
        foreach (xray_get_bgp_filters_from_config() as $uuid => $f) {
            $ident = xray_bird_filter_ident((string)($f['name'] ?? ''));
            if ($ident !== '') {
                $byUuid[$uuid] = $ident;
            }
        }
    }
    if (isset($byUuid[$value])) {
        return $byUuid[$value];
    }
    return xray_bird_filter_ident($value);
}

function xray_bird_impexp_line(string $kind, string $value): string
{
    $ident = xray_resolve_import_filter($value);
    if ($ident === '' || strcasecmp($ident, 'none') === 0) {
        return "        {$kind} none;";
    }
    if (strcasecmp($ident, 'all') === 0) {
        return "        {$kind} all;";
    }
    return "        {$kind} filter {$ident};";
}

function xray_bird_protocol_name(string $name, string $uuid, array &$used): string
{
    $n = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    $n = trim($n, '_');
    if ($n === '' || !preg_match('/^[A-Za-z_]/', $n)) {
        $n = 'peer_' . substr(str_replace('-', '', $uuid), 0, 8);
    }
    $base = $n;
    $i    = 2;
    while (isset($used[$n])) {
        $n = $base . '_' . $i;
        $i++;
    }
    $used[$n] = true;
    return $n;
}

function xray_bgp_peer_is_dnstap(array $p): bool
{
    return strcasecmp(trim((string)($p['name'] ?? '')), 'dnstap') === 0;
}

function xray_bird_render_peer(array $p, string $protoName): string
{
    $dnstap  = xray_bgp_peer_is_dnstap($p);
    $neighbor = explode('%', trim((string)($p['neighbor'] ?? '')))[0];
    if ($neighbor !== '' && !xray_is_ipv4_addr($neighbor) && !xray_is_ipv6_addr($neighbor)) {
        if (!function_exists('xray_wan_routes_resolve_neighbor_ip')) {
            $wr = __DIR__ . '/xray-wan-static-routes.php';
            if (is_readable($wr)) {
                require_once $wr;
            }
        }
        if (function_exists('xray_wan_routes_resolve_neighbor_ip')) {
            $prefer6 = (($p['ipv6'] ?? '0') === '1') && (($p['ipv4'] ?? '0') !== '1');
            $resolved = xray_wan_routes_resolve_neighbor_ip($neighbor, $prefer6);
            if ($resolved !== '') {
                $neighbor = $resolved;
            }
        }
    }
    $lines   = [];
    $lines[] = 'protocol bgp ' . $protoName . ' {';
    $lines[] = '    local as ' . (int)$p['local_as'] . ';';
    $lines[] = '    neighbor ' . $neighbor . ' as ' . (int)$p['neighbor_as'] . ';';
    $src = trim(explode('%', (string)($p['source_address'] ?? ''))[0]);
    if ($src !== '' && (xray_is_ipv4_addr($src) || xray_is_ipv6_addr($src))) {
        $lines[] = '    source address ' . $src . ';';
    }
    if (($p['ipv4'] ?? '0') === '1') {
        $lines[] = '    ipv4 {';
        $lines[] = xray_bird_impexp_line('import', (string)($p['ipv4_import'] ?? 'none'));
        $lines[] = '        export none;';
        $lines[] = '    };';
    }
    if (($p['ipv6'] ?? '0') === '1') {
        $lines[] = '    ipv6 {';
        $lines[] = xray_bird_impexp_line('import', (string)($p['ipv6_import'] ?? 'none'));
        $lines[] = '        export none;';
        $lines[] = '    };';
    }
    if (!$dnstap) {
        $lines[] = '    multihop;';
    }
    $lines[] = '    hold time ' . (int)$p['hold_time'] . ';';
    $lines[] = '}';
    $lines[] = '';
    return implode("\n", $lines);
}

function xray_get_bgp_peers_from_config(): array
{
    if (!class_exists('OPNsense\\Core\\Config')) {
        return [];
    }
    $cfg = OPNsense\Core\Config::getInstance()->object();
    if ($cfg === null || !isset($cfg->OPNsense->xray)) {
        return [];
    }
    $node = $cfg->OPNsense->xray->bgppeers ?? null;
    if (!$node || !isset($node->peer)) {
        return [];
    }
    $result = [];
    foreach ($node->peer as $peer) {
        $uuid = (string)$peer['uuid'];
        if ($uuid === '') {
            continue;
        }
        $result[$uuid] = [
            'enabled'        => xray_bgp_flag_enabled($peer->enabled ?? '0') ? '1' : '0',
            'name'           => (string)($peer->name ?? ''),
            'local_as'       => (string)($peer->local_as ?? '65103'),
            'neighbor'       => (string)($peer->neighbor ?? ''),
            'neighbor_as'    => (string)($peer->neighbor_as ?? '65412'),
            'source_address' => (string)($peer->source_address ?? ''),
            'ipv4'           => (string)($peer->ipv4 ?? '1') === '1' ? '1' : '0',
            'ipv4_import'          => (string)($peer->ipv4_import ?? 'none'),
            'ipv4_community_name'  => (string)($peer->ipv4_community_name ?? ''),
            'ipv4_community'       => (string)($peer->ipv4_community ?? ''),
            'ipv6'                 => (string)($peer->ipv6 ?? '0') === '1' ? '1' : '0',
            'ipv6_import'          => (string)($peer->ipv6_import ?? 'none'),
            'ipv6_community_name'  => (string)($peer->ipv6_community_name ?? ''),
            'ipv6_community'       => (string)($peer->ipv6_community ?? ''),
            'hold_time'            => (string)($peer->hold_time ?? '240'),
        ];
    }
    return $result;
}

function xray_bird_inc_filename(string $protoName): string
{
    if (strncasecmp($protoName, 'peer_', 5) !== 0) {
        $protoName = 'peer_' . $protoName;
    }
    return $protoName . '.inc';
}

function xray_bird_write_peers(): void
{
    if (class_exists('OPNsense\\Core\\Config')) {
        try {
            $cfg = \OPNsense\Core\Config::getInstance();
            if (method_exists($cfg, 'forceReload')) {
                $cfg->forceReload();
            }
        } catch (\Throwable $e) {
        }
    }
    $dir = XRAY_BIRD_INC_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    xray_bird_write_router_id();
    xray_bird_write_wan_if();
    xray_bird_write_direct_ifs();
    $commFiles = [];
    $written   = [];
    xray_bird_write_gui_communities($dir, $written, $commFiles);

    $peers      = xray_get_bgp_peers_from_config();
    $used       = [];
    $incDnstap  = [];
    $incOther   = [];

    foreach ($peers as $uuid => $p) {
        if ($p['neighbor'] === '' || (($p['ipv4'] !== '1') && ($p['ipv6'] !== '1'))) {
            continue;
        }
        $proto = xray_bird_protocol_name($p['name'], $uuid, $used);
        $file  = $dir . '/' . xray_bird_inc_filename($proto);
        file_put_contents($file, xray_bird_render_peer($p, $proto));
        @chmod($file, 0644);
        $written[$file] = true;
        if ($p['enabled'] === '1') {
            $line = 'include "' . $file . '";';
            if (xray_bgp_peer_is_dnstap($p)) {
                $incDnstap[] = $line;
            } else {
                $incOther[] = $line;
            }
        }
    }

    $incLines = array_merge(
        ['# generated by os-xray — BGP peer includes from config.xml', ''],
        $incDnstap,
        $incOther
    );

    xray_bird_write_gui_filters($dir, $written);

    $commLines = ['# generated by os-xray — BGP community defines from config.xml', ''];
    $seenInc   = [];
    foreach (array_keys($commFiles) as $cfile) {
        if (!isset($seenInc[$cfile])) {
            $commLines[]     = 'include "' . $cfile . '";';
            $seenInc[$cfile] = true;
        }
    }
    $commLines[] = '';
    file_put_contents($dir . '/communities.inc', implode("\n", $commLines));
    @chmod($dir . '/communities.inc', 0644);

    foreach (['peer_*.inc', 'filter_*.inc', 'community_*.inc', 'peer-*.inc'] as $pat) {
        $globs = glob($dir . '/' . $pat);
        if ($globs === false) {
            continue;
        }
        foreach ($globs as $path) {
            if (!isset($written[$path])) {
                @unlink($path);
            }
        }
    }

    $listBody = implode("\n", array_keys($written));
    if ($listBody !== '') {
        $listBody .= "\n";
    }
    file_put_contents(XRAY_BIRD_GENERATED_LIST, $listBody);
    @chmod(XRAY_BIRD_GENERATED_LIST, 0644);

    $incLines[] = '';
    file_put_contents(XRAY_BIRD_PEERS_INC, implode("\n", $incLines));
    @chmod(XRAY_BIRD_PEERS_INC, 0644);
}

function xray_bird_is_running(): bool
{
    $out = [];
    exec('/usr/sbin/service bird status 2>&1', $out, $rc);
    $text = strtolower(implode("\n", $out));
    if (strpos($text, 'not running') !== false) {
        return false;
    }
    if ($rc === 0 && preg_match('/\bis running\b/', $text)) {
        return true;
    }
    return $rc === 0 && $text !== '';
}

function xray_bird_reload_config(): void
{
    if (!is_executable('/usr/local/sbin/birdc') || !xray_bird_is_running()) {
        return;
    }
    exec('/usr/local/sbin/birdc configure 2>&1');
}

function xray_birdc_query(string $query): string
{
    if (!preg_match('/^[A-Za-z0-9_ ]+$/', $query)) {
        return '';
    }
    $birdc = '/usr/local/sbin/birdc';
    if (!is_executable($birdc)) {
        return '';
    }
    $out = [];
    exec($birdc . ' ' . escapeshellarg($query) . ' 2>&1', $out);
    return implode("\n", $out);
}

function xray_parse_birdc_route_count(string $text): ?int
{
    if (preg_match('/(\d+)\s+of\s+\d+\s+routes/i', $text, $m)) {
        return (int)$m[1];
    }
    return null;
}

function xray_birdc_protocol_table_count(string $proto, string $table): ?int
{
    if ($proto === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $proto)) {
        return null;
    }
    if ($table !== 'master4' && $table !== 'master6') {
        return null;
    }
    return xray_parse_birdc_route_count(
        xray_birdc_query('show route table ' . $table . ' protocol ' . $proto . ' count')
    );
}

function xray_parse_birdc_protocols_all(string $text): array
{
    $result  = [];
    $cur     = null;
    $channel = null;
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        if (preg_match('/^(\S+)\s+BGP\s+\S+\s+(\S+)\s+(\S+)\s*(.*)$/', $line, $m)) {
            if (strcasecmp($m[1], 'Name') === 0) {
                $cur     = null;
                $channel = null;
                continue;
            }
            $cur     = $m[1];
            $channel = null;
            $rest    = trim($m[4]);
            $result[$cur] = [
                'state'     => $m[2],
                'info'      => $rest,
                'imported'  => 0,
                'imported4' => 0,
                'imported6' => 0,
            ];
            if (preg_match('/Channel\s+ipv6/i', $rest)) {
                $channel = 'ipv6';
            } elseif (preg_match('/Channel\s+ipv4/i', $rest)) {
                $channel = 'ipv4';
            }
            continue;
        }
        if ($cur === null) {
            continue;
        }
        if (preg_match('/^\S+\s+(Device|Direct|Kernel|Pipe|RPKI|Static|Babel|OSPF|RIP|BFD)\s+/', $line)) {
            $cur     = null;
            $channel = null;
            continue;
        }
        if (preg_match('/^\s+BGP state:\s+(.+)$/', $line, $sm)) {
            $result[$cur]['info'] = trim($sm[1]);
            continue;
        }
        if (preg_match('/Channel\s+ipv6(?:\s+unicast)?\b/i', $line)) {
            $channel = 'ipv6';
            continue;
        }
        if (preg_match('/Channel\s+ipv4(?:\s+unicast)?\b/i', $line)) {
            $channel = 'ipv4';
            continue;
        }
        if (preg_match('/^\s+Table:\s+(\S+)/', $line, $tm)) {
            $tbl = strtolower($tm[1]);
            if (strpos($tbl, '6') !== false) {
                $channel = 'ipv6';
            } elseif (strpos($tbl, '4') !== false) {
                $channel = 'ipv4';
            }
            continue;
        }
        if (preg_match('/Routes:\s+(\d+)\s+imported/', $line, $rm)) {
            if ($channel === null) {
                continue;
            }
            $n = (int)$rm[1];
            $result[$cur]['imported'] += $n;
            if ($channel === 'ipv6') {
                $result[$cur]['imported6'] += $n;
            } else {
                $result[$cur]['imported4'] += $n;
            }
        }
    }
    return $result;
}

function xray_bgp_peer_protocol_names(): array
{
    $peers = xray_get_bgp_peers_from_config();
    $used  = [];
    $map   = [];
    foreach ($peers as $uuid => $p) {
        $name = (string)($p['name'] ?? '');
        $map[$uuid] = xray_bird_protocol_name($name !== '' ? $name : 'peer', $uuid, $used);
    }
    return $map;
}

function xray_bgp_peers_runtime_status(): array
{
    $running = xray_bird_is_running();
    if (!$running) {
        return [
            'running' => false,
            'peers'   => [],
        ];
    }
    $peers = xray_get_bgp_peers_from_config();
    $names = xray_bgp_peer_protocol_names();
    $live  = xray_parse_birdc_protocols_all(xray_birdc_query('show protocols all'));
    $result = [];
    foreach ($peers as $uuid => $p) {
        $proto   = $names[$uuid] ?? '';
        $enabled = ($p['enabled'] ?? '0') === '1';
        $row     = [
            'name'      => (string)($p['name'] ?? ''),
            'proto'     => $proto,
            'enabled'   => $enabled,
            'ipv4'      => ($p['ipv4'] ?? '0') === '1',
            'ipv6'      => ($p['ipv6'] ?? '0') === '1',
            'state'     => '—',
            'info'      => '—',
            'imported'  => 0,
            'imported4' => 0,
            'imported6' => 0,
        ];
        if (!$enabled) {
            $row['state'] = 'disabled';
            $row['info']  = 'not in BIRD config';
        } elseif ($proto !== '' && isset($live[$proto])) {
            $row['state']     = $live[$proto]['state'];
            $row['info']      = $live[$proto]['info'] !== '' ? $live[$proto]['info'] : '—';
            $row['imported']  = $live[$proto]['imported'];
            $row['imported4'] = $live[$proto]['imported4'] ?? 0;
            $row['imported6'] = $live[$proto]['imported6'] ?? 0;
            // table count — только запасной источник: после смены ACTIVE_TUN6_IF
            // `show route table master6 protocol … count` может дать 0
            // (недостижимый IPv6 via новый TUN), хотя Channel ipv6 imported > 0.
            if ((int)$row['imported4'] === 0) {
                $n4 = xray_birdc_protocol_table_count($proto, 'master4');
                if ($n4 !== null && $n4 > 0) {
                    $row['imported4'] = $n4;
                }
            }
            if ((int)$row['imported6'] === 0) {
                $n6 = xray_birdc_protocol_table_count($proto, 'master6');
                if ($n6 !== null && $n6 > 0) {
                    $row['imported6'] = $n6;
                }
            }
            $row['imported'] = $row['imported4'] + $row['imported6'];
        } else {
            $row['state'] = 'down';
            $row['info']  = 'not in BIRD';
        }
        $result[$uuid] = $row;
    }
    return [
        'running' => true,
        'peers'   => $result,
    ];
}

function xray_xml_set_child(\SimpleXMLElement $node, string $name, string $value): void
{
    if (!isset($node->{$name})) {
        $node->addChild($name, $value);
        return;
    }
    $node->{$name} = $value;
}

function xray_xml_new_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
        . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

function xray_xml_find_named($parent, string $child, string $name)
{
    if ($parent === null || !isset($parent->{$child})) {
        return null;
    }
    foreach ($parent->{$child} as $item) {
        if (strcasecmp(trim((string)($item->name ?? '')), $name) === 0) {
            return $item;
        }
    }
    return null;
}

function xray_dnstap_ensure_bird_policy(\SimpleXMLElement $xray, int $localAs): array
{
    if ($localAs <= 0) {
        $localAs = 65103;
    }
    if (!isset($xray->bgpcommunities)) {
        $xray->addChild('bgpcommunities');
    }
    if (!isset($xray->bgpfilters)) {
        $xray->addChild('bgpfilters');
    }
    $commName = 'community_DNSTAP_PROXY';
    $commVal  = $localAs . ':777';
    $comm     = xray_xml_find_named($xray->bgpcommunities, 'community', $commName);
    if ($comm === null) {
        $comm = $xray->bgpcommunities->addChild('community');
        $comm->addAttribute('uuid', xray_xml_new_uuid());
        $comm->addChild('enabled', '1');
        $comm->addChild('name', $commName);
        $comm->addChild('communities', $commVal);
    } else {
        xray_xml_set_child($comm, 'enabled', '1');
        xray_xml_set_child($comm, 'communities', $commVal);
    }
    $commUuid = (string)$comm['uuid'];
    $directName = 'community_DNSTAP_DIRECT';
    $directVal  = $localAs . ':555';
    $directComm = xray_xml_find_named($xray->bgpcommunities, 'community', $directName);
    if ($directComm === null) {
        $directComm = $xray->bgpcommunities->addChild('community');
        $directComm->addAttribute('uuid', xray_xml_new_uuid());
        $directComm->addChild('enabled', '1');
        $directComm->addChild('name', $directName);
        $directComm->addChild('communities', $directVal);
    } else {
        xray_xml_set_child($directComm, 'enabled', '1');
        xray_xml_set_child($directComm, 'communities', $directVal);
    }
    $blockName = 'community_DNSTAP_BLOCK';
    $blockVal  = $localAs . ':888';
    $blockComm = xray_xml_find_named($xray->bgpcommunities, 'community', $blockName);
    if ($blockComm === null) {
        $blockComm = $xray->bgpcommunities->addChild('community');
        $blockComm->addAttribute('uuid', xray_xml_new_uuid());
        $blockComm->addChild('enabled', '1');
        $blockComm->addChild('name', $blockName);
        $blockComm->addChild('communities', $blockVal);
    } else {
        xray_xml_set_child($blockComm, 'enabled', '1');
        xray_xml_set_child($blockComm, 'communities', $blockVal);
    }
    $uuids    = ['community' => $commUuid];
    foreach ([
        'filter_dnstap_v4' => ['family' => 'ipv4', 'tun_if' => 'ACTIVE_TUN4_IF'],
        'filter_dnstap_v6' => ['family' => 'ipv6', 'tun_if' => 'ACTIVE_TUN6_IF'],
    ] as $fname => $meta) {
        $f = xray_xml_find_named($xray->bgpfilters, 'filter', $fname);
        $created = false;
        if ($f === null) {
            $f = $xray->bgpfilters->addChild('filter');
            $f->addAttribute('uuid', xray_xml_new_uuid());
            $f->addChild('name', $fname);
            $created = true;
        }
        xray_xml_set_child($f, 'enabled', '1');
        xray_xml_set_child($f, 'name', $fname);
        xray_xml_set_child($f, 'community', $commUuid !== '' ? $commUuid : $commName);
        xray_xml_set_child($f, 'family', $meta['family']);
        $curIf = trim((string)($f->tun_if ?? ''));
        if ($created || $curIf === '') {
            xray_xml_set_child($f, 'tun_if', $meta['tun_if']);
        }
        $uuids[$fname] = (string)$f['uuid'];
    }
    return $uuids;
}

/**
 * Create or update the dnstap BGP peer in config.xml, rewrite BIRD includes, birdc configure.
 */
function xray_dnstap_sync_bird_peer(bool $enable, array $params): void
{
    $neighbor = trim((string)($params['jail'] ?? ''));
    $source   = trim((string)($params['host'] ?? ''));
    $localAs  = (int)($params['local_as'] ?? 65103);
    $useV6    = array_key_exists('ipv6', $params) ? !empty($params['ipv6']) : true;
    if ($neighbor === '') {
        echo "dnstap: skip BIRD peer — empty neighbor\n";
        return;
    }

    try {
        $cnf = \OPNsense\Core\Config::getInstance();
        if (method_exists($cnf, 'forceReload')) {
            $cnf->forceReload();
        }
        $obj = $cnf->object();
        if (!isset($obj->OPNsense->xray)) {
            echo "dnstap: no OPNsense/xray in config.xml\n";
            return;
        }
        $xray = $obj->OPNsense->xray;
        if (!isset($xray->bgppeers)) {
            $xray->addChild('bgppeers');
        }
        $bgp = $xray->bgppeers;
        if ($localAs <= 0) {
            $localAs = 65103;
        }
        if (isset($bgp->peer)) {
            foreach ($bgp->peer as $peer) {
                $n = trim((string)($peer->name ?? ''));
                if (strcasecmp($n, 'dnstap') === 0) {
                    continue;
                }
                $las = (int)($peer->local_as ?? 0);
                if ($las > 0) {
                    $localAs = $las;
                    break;
                }
            }
        }

        $found = null;
        if (isset($bgp->peer)) {
            foreach ($bgp->peer as $peer) {
                if (strcasecmp(trim((string)($peer->name ?? '')), 'dnstap') === 0) {
                    $found = $peer;
                    break;
                }
            }
        }
        $policy = xray_dnstap_ensure_bird_policy($xray, $localAs);

        if ($found === null) {
            $found = $bgp->addChild('peer');
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            $h = bin2hex($b);
            $uuid = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
                . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
            $found->addAttribute('uuid', $uuid);
            $found->addChild('name', 'dnstap');
        }

        xray_xml_set_child($found, 'enabled', $enable ? '1' : '0');
        xray_xml_set_child($found, 'name', 'dnstap');
        xray_xml_set_child($found, 'local_as', (string)$localAs);
        xray_xml_set_child($found, 'neighbor', $neighbor);
        xray_xml_set_child($found, 'neighbor_as', (string)$localAs);
        xray_xml_set_child($found, 'source_address', $source);
        xray_xml_set_child($found, 'ipv4', '1');
        xray_xml_set_child($found, 'ipv6', $useV6 ? '1' : '0');
        xray_xml_set_child($found, 'ipv4_import', (string)($policy['filter_dnstap_v4'] ?? 'filter_dnstap_v4'));
        xray_xml_set_child($found, 'ipv6_import', (string)($policy['filter_dnstap_v6'] ?? 'filter_dnstap_v6'));
        xray_xml_set_child($found, 'ipv4_community_name', 'community_DNSTAP_PROXY');
        xray_xml_set_child($found, 'ipv6_community_name', 'community_DNSTAP_PROXY');
        xray_xml_set_child($found, 'ipv4_community', $localAs . ':777');
        xray_xml_set_child($found, 'ipv6_community', $localAs . ':777');
        xray_xml_set_child($found, 'ipv4_export', 'none');
        xray_xml_set_child($found, 'ipv6_export', 'none');
        xray_xml_set_child($found, 'multihop', '0');
        xray_xml_set_child($found, 'hold_time', '240');

        $cnf->save();
        if (method_exists($cnf, 'forceReload')) {
            $cnf->forceReload();
        }
        foreach ([
            \OPNsense\Xray\BgpFilter::class,
            \OPNsense\Xray\BgpCommunity::class,
            \OPNsense\Xray\BgpPeer::class,
        ] as $cls) {
            if (class_exists($cls) && method_exists($cls, 'flushCacheData')) {
                $cls::flushCacheData();
            }
        }
        echo "dnstap: BIRD peer dnstap neighbor {$neighbor} as {$localAs} (iBGP) enabled=" . ($enable ? '1' : '0') . " ipv6=" . ($useV6 ? '1' : '0') . "\n";
    } catch (\Throwable $e) {
        echo "dnstap: config.xml peer update failed: " . $e->getMessage() . "\n";
    }

    try {
        xray_bird_write_peers();
        xray_bird_reload_config();
        echo "dnstap: BIRD includes rewritten, birdc configure\n";
    } catch (\Throwable $e) {
        echo "WARN BIRD rewrite/reload failed: " . $e->getMessage() . "\n";
    }
}
