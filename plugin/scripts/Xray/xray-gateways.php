<?php
/**
 * Ensure OPNsense gateway_item entries for WAN and Xray instance TUN interfaces.
 *
 * WAN: detect live default GW (IPv4 always; IPv6 when General → Interface WAN6 is set).
 * Instances: peer IP from assigned OPNsense interface (/30 → far end), create if missing.
 */

/**
 * IPv6 gateway management is on when General has Interface WAN6.
 */
function xray_gateways_ipv6_enabled(): bool
{
    try {
        $g = \OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->general ?? null;
        return trim((string)($g->bgp_if_wan6 ?? '')) !== '';
    } catch (\Throwable $e) {
        return false;
    }
}

function xray_gateways_new_uuid(): string
{
    if (function_exists('xray_xml_new_uuid')) {
        return xray_xml_new_uuid();
    }
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
        . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * Logical OPNsense interface name (wan, opt3, …) for a FreeBSD device.
 */
function xray_gateways_logical_if_for_device(string $device): string
{
    $device = trim($device);
    if ($device === '') {
        return '';
    }
    try {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        if (!isset($cfg->interfaces)) {
            return '';
        }
        foreach ($cfg->interfaces->children() as $name => $iface) {
            $key = (string)$name;
            $ifAttr = trim((string)($iface->if ?? ''));
            if ($ifAttr !== '' && strcasecmp($ifAttr, $device) === 0) {
                return $key;
            }
            if (function_exists('get_real_interface')) {
                foreach (['inet', 'inet6'] as $fam) {
                    $real = trim((string)get_real_interface($key, $fam));
                    if ($real !== '' && strcasecmp($real, $device) === 0) {
                        return $key;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
    }
    return '';
}

/**
 * Point-to-point peer from local IPv4 + prefix (typical TUN /30).
 */
function xray_gateways_ipv4_peer(string $local, int $bits): string
{
    $local = trim($local);
    if (filter_var($local, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return '';
    }
    $long = ip2long($local);
    if ($long === false) {
        return '';
    }
    if ($bits === 30) {
        $base = $long & ~3;
        $host = $long & 3;
        if ($host === 1) {
            return long2ip($base + 2);
        }
        if ($host === 2) {
            return long2ip($base + 1);
        }
        return '';
    }
    if ($bits === 31) {
        return long2ip($long ^ 1);
    }
    return '';
}

/**
 * Point-to-point peer from local IPv6 + prefix (/126 or /127).
 */
function xray_gateways_ipv6_peer(string $local, int $bits): string
{
    $local = explode('%', trim($local))[0];
    if (filter_var($local, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return '';
    }
    $bin = @inet_pton($local);
    if ($bin === false || ($bits !== 126 && $bits !== 127)) {
        return '';
    }
    $bytes = unpack('C*', $bin);
    if ($bytes === false || count($bytes) !== 16) {
        return '';
    }
    $idx = 16;
    if ($bits === 127) {
        $bytes[$idx] ^= 0x01;
    } else {
        // /126: flip low 2 bits between the two host values (…1 ↔ …2)
        $low = $bytes[$idx] & 0x03;
        if ($low === 1) {
            $bytes[$idx] = ($bytes[$idx] & ~0x03) | 2;
        } elseif ($low === 2) {
            $bytes[$idx] = ($bytes[$idx] & ~0x03) | 1;
        } else {
            return '';
        }
    }
    $out = '';
    for ($i = 1; $i <= 16; $i++) {
        $out .= chr($bytes[$i]);
    }
    $peer = @inet_ntop($out);
    return is_string($peer) ? $peer : '';
}

/**
 * Local address + prefix on FreeBSD device (from ifconfig).
 *
 * @return array{ip:string,bits:int}
 */
function xray_gateways_ifconfig_addr(string $device, bool $v6): array
{
    $out = [];
    exec('/sbin/ifconfig ' . escapeshellarg($device) . ' 2>/dev/null', $out);
    if ($v6) {
        foreach ($out as $line) {
            if (!preg_match('/inet6\s+([0-9a-fA-F:]+)/', $line, $m)) {
                continue;
            }
            $ip = explode('%', $m[1])[0];
            if (strncasecmp($ip, 'fe80:', 5) === 0 || $ip === '::1') {
                continue;
            }
            $bits = 128;
            if (preg_match('/prefixlen\s+(\d+)/', $line, $pm)) {
                $bits = (int)$pm[1];
            }
            return ['ip' => $ip, 'bits' => $bits];
        }
        return ['ip' => '', 'bits' => 0];
    }
    foreach ($out as $line) {
        if (!preg_match('/inet\s+(\S+)/', $line, $m)) {
            continue;
        }
        $ip = $m[1];
        if ($ip === '127.0.0.1') {
            continue;
        }
        $bits = 32;
        if (preg_match('/netmask\s+(0x[0-9a-fA-F]+)/', $line, $nm)) {
            $dec = hexdec(substr($nm[1], 2));
            $bits = 0;
            for ($i = 31; $i >= 0; $i--) {
                if ($dec & (1 << $i)) {
                    $bits++;
                }
            }
        } elseif (preg_match('/\/(\d+)/', $line, $cm)) {
            $bits = (int)$cm[1];
        }
        return ['ip' => $ip, 'bits' => $bits];
    }
    return ['ip' => '', 'bits' => 0];
}

/**
 * Local IP/prefix from config.xml interfaces.<logical>.
 *
 * @return array{ip:string,bits:int}
 */
function xray_gateways_cfg_iface_addr(string $logical, bool $v6): array
{
    try {
        $iface = \OPNsense\Core\Config::getInstance()->object()->interfaces->{$logical} ?? null;
        if ($iface === null) {
            return ['ip' => '', 'bits' => 0];
        }
        $raw = $v6
            ? trim((string)($iface->ipaddrv6 ?? ''))
            : trim((string)($iface->ipaddr ?? ''));
        if ($raw === '' || strcasecmp($raw, 'dhcp') === 0 || strcasecmp($raw, 'none') === 0) {
            return ['ip' => '', 'bits' => 0];
        }
        $ip = $raw;
        $bits = $v6
            ? (int)(string)($iface->subnetv6 ?? 128)
            : (int)(string)($iface->subnet ?? 32);
        if (strpos($raw, '/') !== false) {
            [$ip, $b] = explode('/', $raw, 2);
            $bits = (int)$b;
        }
        $flags = $v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return ['ip' => '', 'bits' => 0];
        }
        if ($bits <= 0) {
            $bits = $v6 ? 128 : 32;
        }
        return ['ip' => $ip, 'bits' => $bits];
    } catch (\Throwable $e) {
        return ['ip' => '', 'bits' => 0];
    }
}

/**
 * Resolve peer gateway IP for an assigned instance interface.
 */
function xray_gateways_instance_peer(string $logical, string $device, bool $v6): string
{
    $cfg = xray_gateways_cfg_iface_addr($logical, $v6);
    if ($cfg['ip'] !== '') {
        $peer = $v6
            ? xray_gateways_ipv6_peer($cfg['ip'], $cfg['bits'])
            : xray_gateways_ipv4_peer($cfg['ip'], $cfg['bits']);
        if ($peer !== '') {
            return $peer;
        }
    }
    $live = xray_gateways_ifconfig_addr($device, $v6);
    if ($live['ip'] === '') {
        return '';
    }
    return $v6
        ? xray_gateways_ipv6_peer($live['ip'], $live['bits'])
        : xray_gateways_ipv4_peer($live['ip'], $live['bits']);
}

/**
 * Sanitize gateway name (OPNsense: alphanumeric + underscore).
 */
function xray_gateways_sanitize_name(string $name, string $fallback): string
{
    $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '';
    $name = trim($name, '_');
    if ($name === '') {
        $name = $fallback;
    }
    if ($name !== '' && !preg_match('/^[A-Za-z]/', $name)) {
        $name = 'GW_' . $name;
    }
    return substr($name, 0, 32);
}

/**
 * Find existing gateway_item by interface + ipprotocol (legacy + MVC paths).
 *
 * @return object|null SimpleXMLElement
 */
function xray_gateways_find_item($gatewaysParent, string $logical, string $ipproto)
{
    if ($gatewaysParent === null || !isset($gatewaysParent->gateway_item)) {
        return null;
    }
    foreach ($gatewaysParent->gateway_item as $item) {
        if (strcasecmp(trim((string)($item->interface ?? '')), $logical) !== 0) {
            continue;
        }
        $proto = trim((string)($item->ipprotocol ?? 'inet'));
        if ($proto === '') {
            $proto = 'inet';
        }
        if (strcasecmp($proto, $ipproto) === 0) {
            return $item;
        }
    }
    return null;
}

/**
 * Parent node that holds gateway_item (legacy <gateways> preferred).
 */
function xray_gateways_parent($obj)
{
    if (isset($obj->gateways)) {
        return $obj->gateways;
    }
    if (isset($obj->OPNsense->Gateways)) {
        return $obj->OPNsense->Gateways;
    }
    return null;
}

/**
 * Current General → Routing strategy.
 */
function xray_gateways_routing_strategy(): string
{
    try {
        $g = \OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->general ?? null;
        $s = trim((string)($g->routing_strategy ?? 'system_policy'));
        return $s !== '' ? $s : 'system_policy';
    } catch (\Throwable $e) {
        return 'system_policy';
    }
}

/** General → Enable BGP. */
function xray_gateways_bgp_enabled(): bool
{
    try {
        $g = \OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->general ?? null;
        $v = strtolower(trim((string)($g->bgp_enabled ?? '0')));
        return $v === '1' || $v === 'on' || $v === 'true' || $v === 'yes';
    } catch (\Throwable $e) {
        return false;
    }
}

/** General → Auto route. */
function xray_gateways_autoroute_enabled(): bool
{
    try {
        $g = \OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->general ?? null;
        $v = strtolower(trim((string)($g->bgp_auto_route ?? '0')));
        return $v === '1' || $v === 'on' || $v === 'true' || $v === 'yes';
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * PROXY-first GW mode: strategy BLOCK→PROXY→DIRECT and BGP + Auto route are on.
 * Otherwise WAN must keep Upstream (defaultgw=1).
 */
function xray_gateways_proxy_first_active(): bool
{
    return xray_gateways_routing_strategy() === 'block_proxy_direct'
        && xray_gateways_bgp_enabled()
        && xray_gateways_autoroute_enabled();
}

/**
 * Priorities / flags by routing strategy and BGP/Auto route.
 *
 * PROXY-first (strategy + BGP + Auto route): active_tun GW=100 + Upstream,
 *   other instances=255 Upstream, WAN=255 without Upstream.
 * Otherwise (system_policy / DIRECT-first / BGP off / Auto route off / uninstall):
 *   WAN=100 + Upstream, instances=255, inst defaultgw=0.
 *
 * @return array{wan:int,inst:int,prefer_active:bool,inst_defaultgw:bool,wan_defaultgw:bool}
 */
function xray_gateways_priority_plan(): array
{
    if (xray_gateways_proxy_first_active()) {
        return [
            'wan' => 255,
            'inst' => 255,
            'prefer_active' => true,
            'inst_defaultgw' => true,
            'wan_defaultgw' => false,
        ];
    }
    // system_policy, block_direct_proxy, or PROXY-first with BGP/autoroute off
    return [
        'wan' => 100,
        'inst' => 255,
        'prefer_active' => false,
        'inst_defaultgw' => false,
        'wan_defaultgw' => true,
    ];
}

/**
 * Ensure one gateway_item exists. Returns true if created or updated.
 *
 * @param bool $updatePriority when false, leave priority on existing items unchanged
 */
function xray_gateways_ensure_item(
    string $logical,
    string $ipproto,
    string $gatewayIp,
    string $name,
    bool $defaultgw = false,
    bool $fargw = true,
    int $priority = 255,
    bool $createOnly = false,
    bool $updatePriority = true
): bool {
    if ($priority < 1) {
        $priority = 1;
    }
    if ($priority > 255) {
        $priority = 255;
    }
    $prioStr = (string)$priority;
    $logical = trim($logical);
    $gatewayIp = explode('%', trim($gatewayIp))[0];
    $ipproto = ($ipproto === 'inet6') ? 'inet6' : 'inet';
    if ($logical === '' || $gatewayIp === '') {
        return false;
    }
    if ($ipproto === 'inet6') {
        if (filter_var($gatewayIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
    } elseif (filter_var($gatewayIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    try {
        $cnf = \OPNsense\Core\Config::getInstance();
        $obj = $cnf->object();
        $parent = xray_gateways_parent($obj);
        if ($parent === null) {
            $parent = $obj->addChild('gateways');
        }

        $existing = xray_gateways_find_item($parent, $logical, $ipproto);
        if ($existing !== null) {
            if ($createOnly) {
                return false;
            }
            $cur = explode('%', trim((string)($existing->gateway ?? '')))[0];
            $changed = false;
            $setChild = function ($node, string $key, string $val) use (&$changed): void {
                if ((string)($node->{$key} ?? '') === $val) {
                    return;
                }
                if (!isset($node->{$key})) {
                    $node->addChild($key, $val);
                } else {
                    $node->{$key} = $val;
                }
                $changed = true;
            };
            if ($cur === '' || strcasecmp($cur, 'dynamic') === 0) {
                $existing->gateway = $gatewayIp;
                $changed = true;
                $cur = $gatewayIp;
            }
            // Far Gateway + Upstream (defaultgw) — always sync both flags
            $setChild($existing, 'fargw', $fargw ? '1' : '0');
            $setChild($existing, 'defaultgw', $defaultgw ? '1' : '0');
            if ($updatePriority) {
                $setChild($existing, 'priority', $prioStr);
            }
            $setChild($existing, 'monitor_disable', '1');
            $setChild($existing, 'force_down', '0');
            $prioLog = $updatePriority
                ? $prioStr
                : trim((string)($existing->priority ?? ''));
            if ($changed) {
                $cnf->save();
                echo "gateways: updated {$name} ({$logical}/{$ipproto}) → {$cur}"
                    . " priority={$prioLog}"
                    . ' fargw=' . ($fargw ? '1' : '0')
                    . ' defaultgw=' . ($defaultgw ? '1' : '0')
                    . "\n";
            } else {
                echo "gateways: OK {$name} ({$logical}/{$ipproto}) {$cur} priority={$prioLog}\n";
            }
            return $changed;
        }

        $name = xray_gateways_sanitize_name($name, $logical . ($ipproto === 'inet6' ? '_IPv6' : '_GW'));
        // Avoid duplicate names
        $base = $name;
        $n = 2;
        $nameTaken = static function (string $want) use ($parent): bool {
            if (!isset($parent->gateway_item)) {
                return false;
            }
            foreach ($parent->gateway_item as $it) {
                if (strcasecmp(trim((string)($it->name ?? '')), $want) === 0) {
                    return true;
                }
            }
            return false;
        };
        while ($nameTaken($name)) {
            $name = substr($base, 0, 28) . '_' . $n;
            $n++;
            if ($n > 50) {
                break;
            }
        }

        $item = $parent->addChild('gateway_item');
        $item->addAttribute('uuid', xray_gateways_new_uuid());
        $fields = [
            'disabled' => '0',
            'name' => $name,
            'descr' => '',
            'interface' => $logical,
            'ipprotocol' => $ipproto,
            'gateway' => $gatewayIp,
            'defaultgw' => $defaultgw ? '1' : '0',
            'fargw' => $fargw ? '1' : '0',
            'monitor_disable' => '1',
            'monitor_noroute' => '0',
            'monitor_killstates' => '0',
            'monitor_killstates_priority' => '0',
            'monitor' => '',
            'force_down' => '0',
            'nosync' => '0',
            'priority' => $prioStr,
            'weight' => '1',
            'latencylow' => '',
            'latencyhigh' => '',
            'losslow' => '',
            'losshigh' => '',
            'interval' => '',
            'time_period' => '',
            'loss_interval' => '',
            'data_length' => '',
        ];
        foreach ($fields as $k => $v) {
            $item->addChild($k, $v);
        }
        $cnf->save();
        echo "gateways: created {$name} ({$logical}/{$ipproto}) → {$gatewayIp} priority={$prioStr}\n";
        return true;
    } catch (\Throwable $e) {
        echo 'gateways: ERROR ' . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * @return bool true if config.xml changed
 */
function xray_gateways_ensure_wan(int $wanPriority = 100, bool $wanDefaultgw = true): bool
{
    $changed = false;
    $want4 = function_exists('xray_bird_wan4_dev') ? xray_bird_wan4_dev() : '';
    $logical4 = $want4 !== '' ? xray_gateways_logical_if_for_device($want4) : '';
    if ($logical4 === '') {
        $logical4 = 'wan';
    }
    $gw4 = function_exists('xray_wan_default_gateway4') ? xray_wan_default_gateway4() : '';
    if ($gw4 === '' && class_exists('\\OPNsense\\Routing\\Gateways')) {
        try {
            $gws = new \OPNsense\Routing\Gateways();
            $gw4 = trim((string)$gws->getInterfaceGateway($logical4, 'inet'));
        } catch (\Throwable $e) {
        }
    }
    if ($gw4 !== '') {
        // WAN IPv4: Upstream only when strategy says so; never Far Gateway
        if (xray_gateways_ensure_item(
            $logical4,
            'inet',
            $gw4,
            'WAN_GW',
            $wanDefaultgw,
            false,
            $wanPriority
        )) {
            $changed = true;
        }
    } else {
        echo "gateways: WAN IPv4 gateway not found (skip create)\n";
    }

    if (!xray_gateways_ipv6_enabled()) {
        echo "gateways: IPv6 skipped (General → Interface WAN6 empty)\n";
        return $changed;
    }
    $want6 = function_exists('xray_bird_wan6_dev') ? xray_bird_wan6_dev() : '';
    $logical6 = $want6 !== '' ? xray_gateways_logical_if_for_device($want6) : '';
    if ($logical6 === '') {
        $logical6 = $logical4 !== '' ? $logical4 : 'wan';
    }
    $gw6 = function_exists('xray_wan_default_gateway6') ? xray_wan_default_gateway6() : '';
    if ($gw6 === '' && class_exists('\\OPNsense\\Routing\\Gateways')) {
        try {
            $gws = new \OPNsense\Routing\Gateways();
            $gw6 = explode('%', trim((string)$gws->getInterfaceGateway($logical6, 'inet6')))[0];
        } catch (\Throwable $e) {
        }
    }
    if ($gw6 !== '') {
        // WAN IPv6: same Upstream flag as IPv4; Far Gateway stays on (typical for link-local gw)
        if (xray_gateways_ensure_item(
            $logical6,
            'inet6',
            $gw6,
            'DefaultGW_IPv6',
            $wanDefaultgw,
            true,
            $wanPriority
        )) {
            $changed = true;
        }
    } else {
        echo "gateways: WAN IPv6 gateway not found (skip create)\n";
    }
    return $changed;
}

/**
 * @param array{wan:int,inst:int,prefer_active:bool,inst_defaultgw?:bool} $plan
 * @return bool true if config.xml changed
 */
function xray_gateways_ensure_instances(array $plan): bool
{
    $changed = false;
    $ipv6On = xray_gateways_ipv6_enabled();
    $active4 = function_exists('xray_bird_active_tun_if')
        ? xray_bird_active_tun_if('ipv4')
        : '';
    $active6 = function_exists('xray_bird_active_tun_if')
        ? xray_bird_active_tun_if('ipv6')
        : '';
    $prefer = !empty($plan['prefer_active']);
    $instPrio = (int)($plan['inst'] ?? 255);
    $instDefaultgw = !empty($plan['inst_defaultgw']);

    try {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        $ins = $cfg->OPNsense->xray->instances ?? null;
        if ($ins === null || !isset($ins->instance)) {
            return false;
        }
        foreach ($ins->instance as $inst) {
            if ((string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $name = trim((string)($inst->name ?? ''));
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                continue;
            }
            $logical = xray_gateways_logical_if_for_device($tun);
            if ($logical === '') {
                echo "gateways: skip instance " . ($name !== '' ? $name : $tun)
                    . " — TUN {$tun} not assigned in Interfaces\n";
                continue;
            }
            $use4 = (string)($inst->use_ipv4 ?? '1') === '1';
            $use6 = (string)($inst->use_ipv6 ?? '0') === '1';
            $base = xray_gateways_sanitize_name($name !== '' ? $name : $logical, 'INST');

            // Instance GWs: fargw always; defaultgw only for BLOCK→PROXY→DIRECT.
            // If peer cannot be derived from if/config (common for IPv6 ≠ /126|/127),
            // fall back to the gateway IP already stored on an existing gateway_item —
            // otherwise strategy changes never sync defaultgw on that family.
            $parent = xray_gateways_parent(\OPNsense\Core\Config::getInstance()->object());
            if ($use4) {
                $existing4 = xray_gateways_find_item($parent, $logical, 'inet');
                $peer4 = xray_gateways_instance_peer($logical, $tun, false);
                if ($peer4 === '' && $existing4 !== null) {
                    $peer4 = explode('%', trim((string)($existing4->gateway ?? '')))[0];
                }
                if ($peer4 !== '') {
                    $prio4 = ($prefer && $active4 !== '' && strcasecmp($tun, $active4) === 0)
                        ? 100
                        : $instPrio;
                    if (xray_gateways_ensure_item(
                        $logical,
                        'inet',
                        $peer4,
                        $base . '_GW',
                        $instDefaultgw,
                        true,
                        $prio4
                    )) {
                        $changed = true;
                        $parent = xray_gateways_parent(\OPNsense\Core\Config::getInstance()->object());
                    }
                } else {
                    echo "gateways: skip {$base} IPv4 — no peer IP (set Static /30 on {$logical})\n";
                }
            }
            if ($use6) {
                $existing6 = xray_gateways_find_item($parent, $logical, 'inet6');
                $peer6 = xray_gateways_instance_peer($logical, $tun, true);
                if ($peer6 === '' && $existing6 !== null) {
                    $peer6 = explode('%', trim((string)($existing6->gateway ?? '')))[0];
                }
                if ($peer6 === '') {
                    if ($ipv6On || $existing6 !== null) {
                        echo "gateways: skip {$base} IPv6 — no peer IP on {$logical}\n";
                    } elseif (!$ipv6On) {
                        echo "gateways: skip {$base} IPv6 — General WAN6 not set\n";
                    }
                } elseif ($existing6 === null && !$ipv6On) {
                    echo "gateways: skip {$base} IPv6 — General WAN6 not set\n";
                } else {
                    $prio6 = ($prefer && $active6 !== '' && strcasecmp($tun, $active6) === 0)
                        ? 100
                        : $instPrio;
                    if (xray_gateways_ensure_item(
                        $logical,
                        'inet6',
                        $peer6,
                        $base . '_GW_IPv6',
                        $instDefaultgw,
                        true,
                        $prio6
                    )) {
                        $changed = true;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        echo 'gateways: instances ERROR ' . $e->getMessage() . "\n";
    }
    return $changed;
}

/**
 * Apply OPNsense routing after gateway XML changes.
 *
 * @param bool|null $forceWanKernel null = auto (WAN unless PROXY-first); true/false override
 */
function xray_gateways_apply_routing(?bool $forceWanKernel = null): void
{
    echo "==> Applying OPNsense routing (configctl routes configure)...\n";
    $bin = '/usr/local/sbin/configctl';
    if (!is_executable($bin)) {
        $bin = '/usr/local/bin/configctl';
    }
    if (!is_executable($bin)) {
        echo "gateways: WARN configctl not found — routing not reloaded\n";
        xray_gateways_ensure_kernel_default($forceWanKernel);
        return;
    }
    $out = [];
    $rc = 0;
    exec(escapeshellarg($bin) . ' routes configure 2>&1', $out, $rc);
    if ($rc === 0) {
        echo "[OK]  routes configure\n";
    } else {
        echo 'gateways: WARN routes configure rc=' . $rc
            . (count($out) ? (' ' . implode(' ', $out)) : '') . "\n";
    }
    // Align netstat with strategy (do not steal TUN default under PROXY-first).
    xray_gateways_ensure_kernel_default($forceWanKernel);
}

/**
 * Logical OPNsense ifaces for all Xray instance TUNs (lowercase => name).
 *
 * @return array<string,string>
 */
function xray_gateways_instance_logicals(): array
{
    $logicals = [];
    try {
        $ins = \OPNsense\Core\Config::getInstance()->object()->OPNsense->xray->instances ?? null;
        if ($ins === null || !isset($ins->instance)) {
            return [];
        }
        foreach ($ins->instance as $inst) {
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                continue;
            }
            $logical = xray_gateways_logical_if_for_device($tun);
            if ($logical !== '') {
                $logicals[strtolower($logical)] = $logical;
            }
        }
    } catch (\Throwable $e) {
    }
    return $logicals;
}

/**
 * Clear Mark Gateway as Down on every instance TUN gateway (inet + inet6).
 * Needed after uninstall: force_down=1 stuck on GWs that ensure_item never touched
 * (e.g. IPv6 peer missing briefly → only the “best” ACTIVE_TUN was updated).
 *
 * @return bool true if config.xml changed
 */
function xray_gateways_clear_instance_force_down(): bool
{
    $changed = false;
    try {
        $cnf = \OPNsense\Core\Config::getInstance();
        $obj = $cnf->object();
        $parent = xray_gateways_parent($obj);
        if ($parent === null || !isset($parent->gateway_item)) {
            return false;
        }
        $logicals = xray_gateways_instance_logicals();
        if ($logicals === []) {
            return false;
        }
        foreach ($parent->gateway_item as $item) {
            $iface = strtolower(trim((string)($item->interface ?? '')));
            if ($iface === '' || !isset($logicals[$iface])) {
                continue;
            }
            if ((string)($item->force_down ?? '0') === '0') {
                continue;
            }
            if (!isset($item->force_down)) {
                $item->addChild('force_down', '0');
            } else {
                $item->force_down = '0';
            }
            $changed = true;
            $name = trim((string)($item->name ?? $iface));
            $fam = trim((string)($item->ipprotocol ?? 'inet'));
            echo "gateways: clear force_down on {$name} ({$fam})\n";
        }
        if ($changed) {
            $cnf->save();
        }
    } catch (\Throwable $e) {
        echo 'gateways: clear force_down ERROR ' . $e->getMessage() . "\n";
        return false;
    }
    return $changed;
}

/**
 * Mark instance TUN gateways offline so they cannot remain the calculated default
 * after TUN is destroyed (uninstall / leave PROXY-first cleanup).
 *
 * @return bool true if config.xml changed
 */
function xray_gateways_mark_instance_gateways_offline(): bool
{
    $changed = false;
    try {
        $cnf = \OPNsense\Core\Config::getInstance();
        $obj = $cnf->object();
        $parent = xray_gateways_parent($obj);
        if ($parent === null || !isset($parent->gateway_item)) {
            return false;
        }
        $logicals = xray_gateways_instance_logicals();
        if ($logicals === []) {
            return false;
        }
        foreach ($parent->gateway_item as $item) {
            $iface = strtolower(trim((string)($item->interface ?? '')));
            if ($iface === '' || !isset($logicals[$iface])) {
                continue;
            }
            $set = static function ($node, string $key, string $val) use (&$changed): void {
                if ((string)($node->{$key} ?? '') === $val) {
                    return;
                }
                if (!isset($node->{$key})) {
                    $node->addChild($key, $val);
                } else {
                    $node->{$key} = $val;
                }
                $changed = true;
            };
            $before = $changed;
            $set($item, 'defaultgw', '0');
            $set($item, 'force_down', '1');
            $set($item, 'priority', '255');
            if ($changed !== $before) {
                $name = trim((string)($item->name ?? $iface));
                echo "gateways: offline instance GW {$name} (defaultgw=0 force_down=1)\n";
            }
        }
        if ($changed) {
            $cnf->save();
        }
    } catch (\Throwable $e) {
        echo 'gateways: mark offline ERROR ' . $e->getMessage() . "\n";
        return false;
    }
    return $changed;
}

/**
 * Peer IP for the current ACTIVE_TUN (instance GW), or ''.
 */
function xray_gateways_active_tun_peer(bool $v6): string
{
    $fam = $v6 ? 'ipv6' : 'ipv4';
    $tun = function_exists('xray_bird_active_tun_if')
        ? trim((string)xray_bird_active_tun_if($fam))
        : '';
    if ($tun === '') {
        return '';
    }
    $logical = xray_gateways_logical_if_for_device($tun);
    if ($logical === '') {
        return '';
    }
    $peer = xray_gateways_instance_peer($logical, $tun, $v6);
    if ($peer !== '') {
        return $peer;
    }
    try {
        $parent = xray_gateways_parent(\OPNsense\Core\Config::getInstance()->object());
        $item = xray_gateways_find_item($parent, $logical, $v6 ? 'inet6' : 'inet');
        if ($item !== null) {
            return explode('%', trim((string)($item->gateway ?? '')))[0];
        }
    } catch (\Throwable $e) {
    }
    return '';
}

/**
 * Align kernel default with strategy.
 * - PROXY-first (BLOCK→PROXY→DIRECT + BGP + Auto route): default via ACTIVE_TUN, never force WAN.
 * - Otherwise / uninstall restore: WAN upstream (missing or still via TUN → WAN).
 *
 * @param bool|null $forceWan true = always prefer WAN; false = PROXY-first TUN; null = auto from plan
 */
function xray_gateways_ensure_kernel_default(?bool $forceWan = null): void
{
    if (!function_exists('xray_route_default_info')) {
        return;
    }
    if ($forceWan === null) {
        $forceWan = !xray_gateways_proxy_first_active();
    }

    $wantDev4 = function_exists('xray_bird_wan4_dev') ? trim((string)xray_bird_wan4_dev()) : '';
    $info = xray_route_default_info('inet');
    $curGw = trim((string)($info['gw'] ?? ''));
    $curIf = trim((string)($info['if'] ?? ''));
    $viaTun = $curIf !== '' && function_exists('xray_is_instance_tun_device')
        && xray_is_instance_tun_device($curIf);
    $missing = ($curGw === '');

    if (!$forceWan) {
        // PROXY-first: never raw `route add` via TUN peer — Far Gateway needs host
        // route + ifa (OPNsense system_routing_configure). Only avoid stealing TUN→WAN.
        if ($viaTun) {
            echo "[OK]  kernel default inet via TUN {$curIf} (PROXY-first)\n";
        } elseif ($missing) {
            echo "gateways: PROXY-first — no inet default yet; relying on routes configure"
                . " (Far Gateway / ACTIVE_TUN)\n";
        } else {
            echo "gateways: PROXY-first — inet default {$curGw} via {$curIf}"
                . " (not forcing WAN; OPNsense picks Upstream by priority)\n";
        }
    } elseif (function_exists('xray_wan_default_gateway4')) {
        $gw4 = trim((string)xray_wan_default_gateway4());
        if ($gw4 !== '') {
            if ($missing || $viaTun) {
                if (!$missing) {
                    exec('/sbin/route delete -inet default >/dev/null 2>&1');
                }
                $out = [];
                $rc = 0;
                exec('/sbin/route add -inet default ' . escapeshellarg($gw4) . ' 2>&1', $out, $rc);
                if ($rc === 0) {
                    echo "[OK]  kernel default inet → {$gw4}"
                        . ($wantDev4 !== '' ? " (WAN {$wantDev4})" : '') . "\n";
                } else {
                    echo 'gateways: WARN route add default inet failed'
                        . (count($out) ? (' ' . implode(' ', $out)) : '') . "\n";
                }
            } else {
                echo "[OK]  kernel default inet already {$curGw} via {$curIf}\n";
            }
        } else {
            echo "gateways: WARN no WAN IPv4 gateway — cannot install kernel default\n";
        }
    }

    if (!xray_gateways_ipv6_enabled()) {
        return;
    }
    $info6 = xray_route_default_info('inet6');
    $curGw6 = trim((string)($info6['gw'] ?? ''));
    $curIf6 = trim((string)($info6['if'] ?? ''));
    $viaTun6 = $curIf6 !== '' && function_exists('xray_is_instance_tun_device')
        && xray_is_instance_tun_device($curIf6);
    $missing6 = ($curGw6 === '');
    $wantDev6 = function_exists('xray_bird_wan6_dev') ? trim((string)xray_bird_wan6_dev()) : '';
    if ($wantDev6 === '') {
        $wantDev6 = $wantDev4;
    }

    if (!$forceWan) {
        if ($viaTun6) {
            echo "[OK]  kernel default inet6 via TUN {$curIf6} (PROXY-first)\n";
        } elseif ($missing6) {
            echo "gateways: PROXY-first — no inet6 default yet; relying on routes configure"
                . " (Far Gateway / ACTIVE_TUN)\n";
        } else {
            echo "gateways: PROXY-first — inet6 default {$curGw6} via {$curIf6}"
                . " (not forcing WAN)\n";
        }
        return;
    }

    if (!function_exists('xray_wan_default_gateway6')) {
        return;
    }
    $gw6 = trim((string)xray_wan_default_gateway6());
    if ($gw6 === '') {
        return;
    }
    if ($missing6 || $viaTun6) {
        if (!$missing6) {
            exec('/sbin/route delete -inet6 default >/dev/null 2>&1');
        }
        $target = $gw6;
        if (stripos($gw6, 'fe80:') === 0 && $wantDev6 !== '') {
            $target = $gw6 . '%' . $wantDev6;
        }
        $out = [];
        $rc = 0;
        exec('/sbin/route add -inet6 default ' . escapeshellarg($target) . ' 2>&1', $out, $rc);
        if ($rc === 0) {
            echo "[OK]  kernel default inet6 → {$target}\n";
        } else {
            echo 'gateways: WARN route add default inet6 failed'
                . (count($out) ? (' ' . implode(' ', $out)) : '') . "\n";
        }
    }
}

/**
 * Instances whose TUN is not in Interfaces → Assignments.
 *
 * @return list<array{name:string,tun:string}>
 */
function xray_gateways_unassigned_instances(): array
{
    $missing = [];
    try {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        $ins = $cfg->OPNsense->xray->instances ?? null;
        if ($ins === null || !isset($ins->instance)) {
            return [];
        }
        foreach ($ins->instance as $inst) {
            if ((string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                continue;
            }
            if (xray_gateways_logical_if_for_device($tun) !== '') {
                continue;
            }
            $name = trim((string)($inst->name ?? ''));
            $missing[] = [
                'name' => $name !== '' ? $name : $tun,
                'tun'  => $tun,
            ];
        }
    } catch (\Throwable $e) {
    }
    return $missing;
}

/**
 * Set priority on an existing gateway_item (interface + family). Returns true if changed.
 */
function xray_gateways_set_priority(string $logical, string $ipproto, int $priority): bool
{
    $logical = trim($logical);
    $ipproto = ($ipproto === 'inet6') ? 'inet6' : 'inet';
    if ($logical === '') {
        return false;
    }
    if ($priority < 1) {
        $priority = 1;
    }
    if ($priority > 255) {
        $priority = 255;
    }
    $prioStr = (string)$priority;
    try {
        $cnf = \OPNsense\Core\Config::getInstance();
        $obj = $cnf->object();
        $parent = xray_gateways_parent($obj);
        $item = xray_gateways_find_item($parent, $logical, $ipproto);
        if ($item === null) {
            return false;
        }
        if ((string)($item->priority ?? '') === $prioStr) {
            return false;
        }
        if (!isset($item->priority)) {
            $item->addChild('priority', $prioStr);
        } else {
            $item->priority = $prioStr;
        }
        $cnf->save();
        return true;
    } catch (\Throwable $e) {
        echo 'gateways: set_priority ERROR ' . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Before Auto route: create any missing instance gateway_item entries.
 * Does NOT run configctl routes configure (autoroute will apply routing later).
 *
 * @return bool true if at least one GW was created
 */
function xray_gateways_ensure_missing_instances_pre_autoroute(): bool
{
    echo "==> Pre-autoroute: check/create missing instance gateways (no routes configure)...\n";
    $created = false;
    $ipv6On = xray_gateways_ipv6_enabled();
    // Temporary priority; autoroute will set 100 / 201+ and then routes configure.
    $instPrio = 255;
    $plan = xray_gateways_priority_plan();
    $instDefaultgw = !empty($plan['inst_defaultgw']);

    try {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        $ins = $cfg->OPNsense->xray->instances ?? null;
        if ($ins === null || !isset($ins->instance)) {
            echo "gateways: pre-autoroute — no instances\n";
            return false;
        }
        $parent = xray_gateways_parent($cfg);
        foreach ($ins->instance as $inst) {
            if ((string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $name = trim((string)($inst->name ?? ''));
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                continue;
            }
            $logical = xray_gateways_logical_if_for_device($tun);
            if ($logical === '') {
                echo "gateways: pre-autoroute skip " . ($name !== '' ? $name : $tun)
                    . " — TUN {$tun} not assigned\n";
                continue;
            }
            $base = xray_gateways_sanitize_name($name !== '' ? $name : $logical, 'INST');
            $use4 = (string)($inst->use_ipv4 ?? '1') === '1';
            $use6 = (string)($inst->use_ipv6 ?? '0') === '1';

            if ($use4) {
                $have4 = xray_gateways_find_item($parent, $logical, 'inet') !== null;
                if (!$have4) {
                    $peer4 = xray_gateways_instance_peer($logical, $tun, false);
                    if ($peer4 === '') {
                        echo "gateways: pre-autoroute skip {$base} IPv4 — no peer IP\n";
                    } elseif (xray_gateways_ensure_item(
                        $logical,
                        'inet',
                        $peer4,
                        $base . '_GW',
                        $instDefaultgw,
                        true,
                        $instPrio,
                        true
                    )) {
                        echo "gateways: pre-autoroute created {$base}_GW\n";
                        $created = true;
                        $parent = xray_gateways_parent(\OPNsense\Core\Config::getInstance()->object());
                    }
                }
            }
            if ($use6 && $ipv6On) {
                $have6 = xray_gateways_find_item($parent, $logical, 'inet6') !== null;
                if (!$have6) {
                    $peer6 = xray_gateways_instance_peer($logical, $tun, true);
                    if ($peer6 === '') {
                        echo "gateways: pre-autoroute skip {$base} IPv6 — no peer IP\n";
                    } elseif (xray_gateways_ensure_item(
                        $logical,
                        'inet6',
                        $peer6,
                        $base . '_GW_IPv6',
                        $instDefaultgw,
                        true,
                        $instPrio,
                        true
                    )) {
                        echo "gateways: pre-autoroute created {$base}_GW_IPv6\n";
                        $created = true;
                        $parent = xray_gateways_parent(\OPNsense\Core\Config::getInstance()->object());
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        echo 'gateways: pre-autoroute ERROR ' . $e->getMessage() . "\n";
    }

    if ($created) {
        echo "gateways: pre-autoroute — missing GWs added (routes configure deferred)\n";
    } else {
        echo "gateways: pre-autoroute — all instance GWs present\n";
    }
    return $created;
}

/**
 * After Auto route (only BLOCK→PROXY→DIRECT): active TUN GW → priority 100;
 * other instance GWs → 200+n by rising HTTP latency (best remaining = 201, then 202, …).
 * Other routing strategies: skip (WAN/instance priorities from strategy / ensure_all).
 *
 * @param list<array{tun?:string,name?:string,v4_ok?:bool,v4_time?:float,v6_ok?:bool,v6_time?:float}> $rows
 */
function xray_gateways_apply_autoroute_priorities(string $active4, string $active6, array $rows): void
{
    $strat = xray_gateways_routing_strategy();
    if (!xray_gateways_proxy_first_active()) {
        echo "==> Gateways priorities after Auto route — skipped"
            . " (need strategy=BLOCK→PROXY→DIRECT + BGP + Auto route;"
            . " now strategy={$strat}"
            . " bgp=" . (xray_gateways_bgp_enabled() ? '1' : '0')
            . " autoroute=" . (xray_gateways_autoroute_enabled() ? '1' : '0') . ")\n";
        return;
    }

    echo "==> Gateways priorities after Auto route"
        . " strategy={$strat}"
        . " active4=" . ($active4 !== '' ? $active4 : 'none')
        . " active6=" . ($active6 !== '' ? $active6 : 'none') . "\n";

    $byTun = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $tun = trim((string)($r['tun'] ?? ''));
        if ($tun === '') {
            continue;
        }
        $byTun[strtolower($tun)] = $r;
    }

    $applyFamily = static function (
        string $family,
        string $activeTun,
        string $okKey,
        string $timeKey
    ) use ($byTun): bool {
        $ipproto = ($family === 'ipv6') ? 'inet6' : 'inet';
        $changed = false;
        $ranked = [];
        foreach ($byTun as $tunKey => $r) {
            $tun = trim((string)($r['tun'] ?? ''));
            $logical = xray_gateways_logical_if_for_device($tun);
            if ($logical === '') {
                continue;
            }
            $ok = !empty($r[$okKey]);
            $time = $ok ? (float)($r[$timeKey] ?? 0) : null;
            $ranked[] = [
                'tun'     => $tun,
                'logical' => $logical,
                'name'    => (string)($r['name'] ?? $tun),
                'ok'      => $ok,
                'time'    => $time,
                'active'  => ($activeTun !== '' && strcasecmp($tun, $activeTun) === 0),
            ];
        }
        if ($ranked === []) {
            return false;
        }

        usort($ranked, static function (array $a, array $b): int {
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1;
            }
            if ($a['ok'] !== $b['ok']) {
                return $a['ok'] ? -1 : 1;
            }
            if ($a['ok'] && $b['ok']) {
                $cmp = $a['time'] <=> $b['time'];
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['tun'], $b['tun']);
        });

        $n = 0;
        foreach ($ranked as $row) {
            if ($row['active']) {
                $prio = 100;
            } else {
                $n++;
                $prio = 200 + $n; // 201, 202, …
                if ($prio > 255) {
                    $prio = 255;
                }
            }
            $label = $row['name'] . '/' . $family;
            if (xray_gateways_set_priority($row['logical'], $ipproto, $prio)) {
                echo "  priority {$prio}: {$label} {$row['tun']}"
                    . ($row['active'] ? ' (active)' : '')
                    . ($row['ok'] ? (' latency=' . sprintf('%.3f', (float)$row['time'])) : ' (no probe)')
                    . "\n";
                $changed = true;
            } else {
                echo "  priority {$prio}: {$label} {$row['tun']} (unchanged or no GW)\n";
            }
        }
        return $changed;
    };

    $changed = false;
    if ($applyFamily('ipv4', $active4, 'v4_ok', 'v4_time')) {
        $changed = true;
    }
    if ($applyFamily('ipv6', $active6, 'v6_ok', 'v6_time')) {
        $changed = true;
    }
    if ($changed) {
        xray_gateways_apply_routing();
    } else {
        echo "gateways: autoroute priorities — no XML changes\n";
    }
}

/**
 * Detect + create/update gateways for WAN and all instances, then reload routing.
 */
function xray_gateways_ensure_all(): void
{
    $strat = xray_gateways_routing_strategy();
    $plan = xray_gateways_priority_plan();
    $wanDefaultgw = !empty($plan['wan_defaultgw']);
    $proxyFirst = xray_gateways_proxy_first_active();
    echo "==> Ensuring OPNsense gateways (WAN + instances)"
        . " strategy={$strat}"
        . " bgp=" . (xray_gateways_bgp_enabled() ? '1' : '0')
        . " autoroute=" . (xray_gateways_autoroute_enabled() ? '1' : '0')
        . " proxy_first=" . ($proxyFirst ? '1' : '0')
        . " wan_prio={$plan['wan']} wan_defaultgw=" . ($wanDefaultgw ? '1' : '0')
        . " inst_prio={$plan['inst']} inst_defaultgw=" . (!empty($plan['inst_defaultgw']) ? '1' : '0')
        . ($plan['prefer_active'] ? ' active_tun_prio=100' : '')
        . "...\n";
    // Drop uninstall leftover Mark Gateway as Down on all instance inet/inet6 GWs.
    xray_gateways_clear_instance_force_down();
    xray_gateways_ensure_wan((int)$plan['wan'], $wanDefaultgw);
    xray_gateways_ensure_instances($plan);
    // Always re-apply so UI (active) and netstat stay aligned after strategy / BGP /
    // Auto route changes (and when WAN Upstream is restored).
    xray_gateways_apply_routing();
}

/**
 * Force WAN Upstream back (defaultgw=1, priority=100) and clear instance Upstream.
 * Used on uninstall and when leaving PROXY-first mode.
 *
 * @param bool $applyRouting run configctl routes configure + kernel default check
 * @param bool $offlineInstances force_down instance GWs (uninstall: TUN will vanish)
 * @return bool true if config.xml changed
 */
function xray_gateways_restore_wan_default(bool $applyRouting = true, bool $offlineInstances = false): bool
{
    echo "==> Restoring WAN Upstream (defaultgw=1, priority=100); clear instance Upstream"
        . ($offlineInstances ? '; mark instance GWs force_down' : '')
        . "...\n";
    $changed = false;
    if (xray_gateways_ensure_wan(100, true)) {
        $changed = true;
    }
    // Clear defaultgw on every enabled instance GW so WAN wins as system default.
    $plan = [
        'wan' => 100,
        'inst' => 255,
        'prefer_active' => false,
        'inst_defaultgw' => false,
        'wan_defaultgw' => true,
    ];
    if (xray_gateways_ensure_instances($plan)) {
        $changed = true;
    }
    if ($offlineInstances && xray_gateways_mark_instance_gateways_offline()) {
        $changed = true;
    }
    if ($applyRouting) {
        // Uninstall / leave PROXY-first: always put WAN back in the kernel table.
        xray_gateways_apply_routing(true);
    }
    return $changed;
}

/**
 * Install / repair: validate instance gateway_item entries.
 * Syncs defaultgw by strategy (1 only for BLOCK→PROXY→DIRECT, else 0), fargw=1,
 * monitor_disable, force_down; creates missing GWs.
 * Does not overwrite priority on existing items (autoroute / strategy keep theirs).
 *
 * @return bool true if config.xml changed
 */
function xray_gateways_repair_instances(bool $applyRouting = true): bool
{
    $plan = xray_gateways_priority_plan();
    $instDefaultgw = !empty($plan['inst_defaultgw']);
    echo "==> Repair instance gateways"
        . " (defaultgw=" . ($instDefaultgw ? '1' : '0')
        . " by strategy; keep existing priority)...\n";
    $changed = xray_gateways_clear_instance_force_down();
    $ipv6On = xray_gateways_ipv6_enabled();
    $instPrio = 255;

    try {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        $ins = $cfg->OPNsense->xray->instances ?? null;
        if ($ins === null || !isset($ins->instance)) {
            echo "gateways: repair — no instances in config\n";
            return false;
        }
        $parent = xray_gateways_parent($cfg);
        foreach ($ins->instance as $inst) {
            if ((string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $name = trim((string)($inst->name ?? ''));
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                continue;
            }
            $logical = xray_gateways_logical_if_for_device($tun);
            if ($logical === '') {
                echo "gateways: repair skip " . ($name !== '' ? $name : $tun)
                    . " — TUN {$tun} not assigned\n";
                continue;
            }
            $base = xray_gateways_sanitize_name($name !== '' ? $name : $logical, 'INST');
            $use4 = (string)($inst->use_ipv4 ?? '1') === '1';
            $use6 = (string)($inst->use_ipv6 ?? '0') === '1';

            if ($use4) {
                $existing = xray_gateways_find_item($parent, $logical, 'inet');
                $peer4 = xray_gateways_instance_peer($logical, $tun, false);
                if ($peer4 === '' && $existing !== null) {
                    $peer4 = explode('%', trim((string)($existing->gateway ?? '')))[0];
                }
                if ($peer4 === '') {
                    echo "gateways: repair skip {$base} IPv4 — no peer IP\n";
                } elseif (xray_gateways_ensure_item(
                    $logical,
                    'inet',
                    $peer4,
                    $base . '_GW',
                    $instDefaultgw,
                    true,
                    $instPrio,
                    false,
                    false
                )) {
                    $changed = true;
                    $parent = xray_gateways_parent(\OPNsense\Core\Config::getInstance()->object());
                }
            }
            if ($use6 && $ipv6On) {
                $existing6 = xray_gateways_find_item($parent, $logical, 'inet6');
                $peer6 = xray_gateways_instance_peer($logical, $tun, true);
                if ($peer6 === '' && $existing6 !== null) {
                    $peer6 = explode('%', trim((string)($existing6->gateway ?? '')))[0];
                }
                if ($peer6 === '') {
                    echo "gateways: repair skip {$base} IPv6 — no peer IP\n";
                } elseif (xray_gateways_ensure_item(
                    $logical,
                    'inet6',
                    $peer6,
                    $base . '_GW_IPv6',
                    $instDefaultgw,
                    true,
                    $instPrio,
                    false,
                    false
                )) {
                    $changed = true;
                    $parent = xray_gateways_parent(\OPNsense\Core\Config::getInstance()->object());
                }
            }
        }
    } catch (\Throwable $e) {
        echo 'gateways: repair ERROR ' . $e->getMessage() . "\n";
        return false;
    }

    if ($changed) {
        echo "gateways: repair — XML updated\n";
        if ($applyRouting) {
            xray_gateways_apply_routing();
        }
    } else {
        echo "gateways: repair — instance GWs already OK\n";
    }
    return $changed;
}

// CLI: php xray-gateways.php repair|ensure
if (PHP_SAPI === 'cli' && isset($argv[0]) && @realpath($argv[0]) === @realpath(__FILE__)) {
    if (!class_exists('OPNsense\\Core\\Config')) {
        $cfgInc = '/usr/local/etc/inc/config.inc';
        if (is_readable($cfgInc)) {
            require_once $cfgInc;
        }
    }
    $bird = __DIR__ . '/xray-bird-peers.php';
    if (is_readable($bird)) {
        require_once $bird;
    }
    $cmd = $argv[1] ?? 'repair';
    if ($cmd === 'repair') {
        xray_gateways_repair_instances(true);
    } elseif ($cmd === 'ensure') {
        xray_gateways_ensure_all();
    } elseif ($cmd === 'restore-wan') {
        xray_gateways_restore_wan_default(true, false);
    } elseif ($cmd === 'restore-wan-uninstall') {
        xray_gateways_restore_wan_default(true, true);
    } else {
        echo "Usage: xray-gateways.php [repair|ensure|restore-wan|restore-wan-uninstall]\n";
        exit(1);
    }
}
