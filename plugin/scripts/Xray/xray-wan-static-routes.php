#!/usr/local/bin/php
<?php
/**
 * Host routes via WAN for BGP peers (except dnstap) and Xray instance servers.
 * Hostname → nslookup (A + AAAA); IP → single /32 or /128.
 */

if (!class_exists('OPNsense\\Core\\Config')) {
    $cfgInc = '/usr/local/etc/inc/config.inc';
    if (is_readable($cfgInc)) {
        require_once $cfgInc;
    }
}
require_once(__DIR__ . '/xray-gateways.php');

const XRAY_WAN_ROUTE_PREFIX = 'os-xray:';

/**
 * @return array{v4:list<string>,v6:list<string>}
 */
function xray_wan_routes_nslookup(string $host): array
{
    $host = explode('%', trim($host))[0];
    $v4 = [];
    $v6 = [];
    if ($host === '') {
        return ['v4' => [], 'v6' => []];
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['v4' => [$host], 'v6' => []];
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['v4' => [], 'v6' => [$host]];
    }

    $parseNslookup = static function (string $type) use ($host): array {
        $lines = [];
        exec(
            'nslookup -type=' . escapeshellarg($type) . ' ' . escapeshellarg($host) . ' 2>/dev/null',
            $lines,
            $rc
        );
        $addrs = [];
        $inAnswer = false;
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(Non-authoritative answer:|Authoritative answers? can be found)/i', $line)) {
                $inAnswer = true;
                continue;
            }
            if (preg_match('/^Name:\s+\S+/i', $line)) {
                $inAnswer = true;
                continue;
            }
            if (!$inAnswer) {
                continue;
            }
            if (preg_match('/^Address(?:es)?:\s*(.+)$/i', $line, $m)) {
                $addr = trim($m[1]);
                $addr = preg_replace('/#\d+$/', '', $addr) ?? $addr;
                $addr = explode('%', trim($addr))[0];
                if ($addr !== '') {
                    $addrs[] = $addr;
                }
            }
        }
        return $addrs;
    };

    foreach ($parseNslookup('A') as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $v4[$ip] = $ip;
        }
    }
    foreach ($parseNslookup('AAAA') as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $v6[$ip] = $ip;
        }
    }

    // Fallback if nslookup parsing failed
    if ($v4 === [] && $v6 === [] && function_exists('dns_get_record')) {
        $a = @dns_get_record($host, DNS_A);
        if (is_array($a)) {
            foreach ($a as $r) {
                $ip = trim((string)($r['ip'] ?? ''));
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $v4[$ip] = $ip;
                }
            }
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                $ip = trim((string)($r['ipv6'] ?? ''));
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $v6[$ip] = $ip;
                }
            }
        }
    }

    return [
        'v4' => array_values($v4),
        'v6' => array_values($v6),
    ];
}

/**
 * Pick one IP for BIRD neighbor line (hostname → prefer A, then AAAA).
 */
function xray_wan_routes_resolve_neighbor_ip(string $neighbor, bool $prefer6 = false): string
{
    $neighbor = explode('%', trim($neighbor))[0];
    if ($neighbor === '') {
        return '';
    }
    if (filter_var($neighbor, FILTER_VALIDATE_IP)) {
        return $neighbor;
    }
    $ips = xray_wan_routes_nslookup($neighbor);
    if ($prefer6) {
        return $ips['v6'][0] ?? ($ips['v4'][0] ?? '');
    }
    return $ips['v4'][0] ?? ($ips['v6'][0] ?? '');
}

function xray_wan_routes_is_usable_ip(string $ip, bool $v6): bool
{
    $ip = explode('%', trim($ip))[0];
    if ($v6) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
        // skip loopback / link-local / ULA? keep ULA; skip link-local + loopback
        if (stripos($ip, 'fe80:') === 0 || $ip === '::1') {
            return false;
        }
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    if (strpos($ip, '127.') === 0) {
        return false;
    }
    return true;
}

/**
 * Gateway name on WAN for family (WAN_GW / DefaultGW_IPv6 or first match).
 */
function xray_wan_routes_gateway_name(string $ipproto): string
{
    $ipproto = ($ipproto === 'inet6') ? 'inet6' : 'inet';
    $prefer = ($ipproto === 'inet6')
        ? ['DefaultGW_IPv6', 'WAN_GW_IPv6', 'WAN_GW']
        : ['WAN_GW'];

    try {
        $obj = \OPNsense\Core\Config::getInstance()->object();
        $parent = xray_gateways_parent($obj);
        if ($parent === null || !isset($parent->gateway_item)) {
            return '';
        }
        $wantDev = '';
        if ($ipproto === 'inet6' && function_exists('xray_bird_wan6_dev')) {
            $wantDev = xray_bird_wan6_dev();
        } elseif ($ipproto === 'inet' && function_exists('xray_bird_wan4_dev')) {
            $wantDev = xray_bird_wan4_dev();
        }
        $logical = $wantDev !== '' ? xray_gateways_logical_if_for_device($wantDev) : '';
        if ($logical === '') {
            $logical = 'wan';
        }

        $candidates = [];
        foreach ($parent->gateway_item as $item) {
            if ((string)($item->disabled ?? '0') === '1') {
                continue;
            }
            $proto = trim((string)($item->ipprotocol ?? 'inet'));
            if ($proto === '') {
                $proto = 'inet';
            }
            if (strcasecmp($proto, $ipproto) !== 0) {
                continue;
            }
            $iface = trim((string)($item->interface ?? ''));
            $name = trim((string)($item->name ?? ''));
            if ($name === '') {
                continue;
            }
            $onWan = ($logical !== '' && strcasecmp($iface, $logical) === 0)
                || strcasecmp($iface, 'wan') === 0;
            if (!$onWan) {
                continue;
            }
            $candidates[$name] = $name;
        }
        foreach ($prefer as $want) {
            if (isset($candidates[$want])) {
                return $want;
            }
        }
        $first = reset($candidates);
        return is_string($first) ? $first : '';
    } catch (\Throwable $e) {
        return '';
    }
}

function xray_wan_routes_network(string $ip, bool $v6): string
{
    $ip = explode('%', trim($ip))[0];
    return $v6 ? ($ip . '/128') : ($ip . '/32');
}

function xray_wan_routes_parent($obj)
{
    if (isset($obj->staticroutes)) {
        return $obj->staticroutes;
    }
    return $obj->addChild('staticroutes');
}

/**
 * @return list<array{network:string,gateway:string,descr:string}>
 */
function xray_wan_routes_collect_desired(): array
{
    $desired = [];
    $gw4 = xray_wan_routes_gateway_name('inet');
    $gw6 = xray_wan_routes_gateway_name('inet6');

    try {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        $xray = $cfg->OPNsense->xray ?? null;
        if ($xray === null) {
            return [];
        }

        // BGP peers (skip dnstap)
        $peers = $xray->bgppeers->peer ?? null;
        if ($peers !== null) {
            foreach ($peers as $peer) {
                $name = trim((string)($peer->name ?? ''));
                if ($name === '' || strcasecmp($name, 'dnstap') === 0) {
                    continue;
                }
                $neighbor = explode('%', trim((string)($peer->neighbor ?? '')))[0];
                if ($neighbor === '') {
                    continue;
                }
                $descr = XRAY_WAN_ROUTE_PREFIX . 'bgp:' . $name;
                $isHost = filter_var($neighbor, FILTER_VALIDATE_IP) === false;
                if ($isHost) {
                    echo "wan-routes: nslookup BGP peer {$name} host={$neighbor}\n";
                }
                $ips = xray_wan_routes_nslookup($neighbor);
                foreach ($ips['v4'] as $ip) {
                    if (!xray_wan_routes_is_usable_ip($ip, false)) {
                        continue;
                    }
                    if ($gw4 === '') {
                        echo "wan-routes: skip {$ip}/32 — no WAN IPv4 gateway\n";
                        continue;
                    }
                    $desired[] = [
                        'network' => xray_wan_routes_network($ip, false),
                        'gateway' => $gw4,
                        'descr'   => $descr,
                    ];
                }
                foreach ($ips['v6'] as $ip) {
                    if (!xray_wan_routes_is_usable_ip($ip, true)) {
                        continue;
                    }
                    if ($gw6 === '') {
                        echo "wan-routes: skip {$ip}/128 — no WAN IPv6 gateway\n";
                        continue;
                    }
                    $desired[] = [
                        'network' => xray_wan_routes_network($ip, true),
                        'gateway' => $gw6,
                        'descr'   => $descr,
                    ];
                }
                if ($ips['v4'] === [] && $ips['v6'] === []) {
                    echo "wan-routes: WARN peer {$name} — no addresses for {$neighbor}\n";
                }
            }
        }

        // Instance servers (outbound vnext address)
        $instances = $xray->instances->instance ?? null;
        if ($instances !== null) {
            foreach ($instances as $inst) {
                $uuid = '';
                $attrs = $inst->attributes();
                if ($attrs !== null && isset($attrs['uuid'])) {
                    $uuid = trim((string)$attrs['uuid']);
                } elseif (isset($inst['uuid'])) {
                    $uuid = trim((string)$inst['uuid']);
                }
                $name = trim((string)($inst->name ?? ''));
                $raw = trim((string)($inst->outbound_config ?? ''));
                if ($raw === '') {
                    continue;
                }
                $ob = json_decode($raw, true);
                $addr = '';
                if (is_array($ob)) {
                    $addr = trim((string)($ob['settings']['vnext'][0]['address'] ?? ''));
                    if ($addr === '' && isset($ob['settings']['servers'][0]['address'])) {
                        $addr = trim((string)$ob['settings']['servers'][0]['address']);
                    }
                }
                if ($addr === '') {
                    continue;
                }
                $tag = $uuid !== '' ? $uuid : ($name !== '' ? $name : 'unknown');
                $descr = XRAY_WAN_ROUTE_PREFIX . 'inst:' . $tag;
                $label = $name !== '' ? $name : $tag;
                $isHost = filter_var($addr, FILTER_VALIDATE_IP) === false;
                if ($isHost) {
                    echo "wan-routes: nslookup instance {$label} host={$addr}\n";
                }
                $ips = xray_wan_routes_nslookup($addr);
                foreach ($ips['v4'] as $ip) {
                    if (!xray_wan_routes_is_usable_ip($ip, false)) {
                        continue;
                    }
                    if ($gw4 === '') {
                        echo "wan-routes: skip {$ip}/32 — no WAN IPv4 gateway\n";
                        continue;
                    }
                    $desired[] = [
                        'network' => xray_wan_routes_network($ip, false),
                        'gateway' => $gw4,
                        'descr'   => $descr,
                    ];
                }
                foreach ($ips['v6'] as $ip) {
                    if (!xray_wan_routes_is_usable_ip($ip, true)) {
                        continue;
                    }
                    if ($gw6 === '') {
                        echo "wan-routes: skip {$ip}/128 — no WAN IPv6 gateway\n";
                        continue;
                    }
                    $desired[] = [
                        'network' => xray_wan_routes_network($ip, true),
                        'gateway' => $gw6,
                        'descr'   => $descr,
                    ];
                }
                if ($ips['v4'] === [] && $ips['v6'] === []) {
                    echo "wan-routes: WARN instance {$label} — no addresses for {$addr}\n";
                }
            }
        }
    } catch (\Throwable $e) {
        echo 'wan-routes: collect ERROR ' . $e->getMessage() . "\n";
    }

    return $desired;
}

/**
 * Sync managed staticroutes via WAN; apply routes configure if XML changed.
 *
 * @return bool true if config changed
 */
function xray_wan_routes_sync_all(bool $applyRouting = true): bool
{
    echo "==> Sync WAN static routes for BGP peers + instance servers...\n";

    // Ensure WAN GW names exist (no routes configure yet)
    if (function_exists('xray_gateways_ensure_wan')) {
        $plan = function_exists('xray_gateways_priority_plan')
            ? xray_gateways_priority_plan()
            : ['wan' => 100, 'wan_defaultgw' => true];
        xray_gateways_ensure_wan(
            (int)($plan['wan'] ?? 100),
            !array_key_exists('wan_defaultgw', $plan) || !empty($plan['wan_defaultgw'])
        );
    }

    $desired = xray_wan_routes_collect_desired();
    $wantKeys = [];
    foreach ($desired as $d) {
        $wantKeys[strtolower($d['network'] . '|' . $d['gateway'] . '|' . $d['descr'])] = $d;
    }

    $changed = false;
    try {
        $cnf = \OPNsense\Core\Config::getInstance();
        $obj = $cnf->object();
        $parent = xray_wan_routes_parent($obj);

        // Remove obsolete managed routes
        if (isset($parent->route)) {
            $removeNodes = [];
            foreach ($parent->route as $route) {
                $descr = trim((string)($route->descr ?? ''));
                if (strpos($descr, XRAY_WAN_ROUTE_PREFIX) !== 0) {
                    continue;
                }
                $net = trim((string)($route->network ?? ''));
                $gw = trim((string)($route->gateway ?? ''));
                $key = strtolower($net . '|' . $gw . '|' . $descr);
                if (!isset($wantKeys[$key])) {
                    $removeNodes[] = $route;
                    echo "wan-routes: remove {$net} via {$gw} ({$descr})\n";
                } else {
                    unset($wantKeys[$key]);
                }
            }
            foreach ($removeNodes as $route) {
                $dom = dom_import_simplexml($route);
                if ($dom !== false && $dom->parentNode !== null) {
                    $dom->parentNode->removeChild($dom);
                    $changed = true;
                }
            }
        }

        // Add missing
        foreach ($wantKeys as $d) {
            $item = $parent->addChild('route');
            if (function_exists('xray_gateways_new_uuid')) {
                $item->addAttribute('uuid', xray_gateways_new_uuid());
            }
            $item->addChild('network', $d['network']);
            $item->addChild('gateway', $d['gateway']);
            $item->addChild('descr', $d['descr']);
            $item->addChild('disabled', '0');
            echo "wan-routes: add {$d['network']} via {$d['gateway']} ({$d['descr']})\n";
            $changed = true;
        }

        if ($changed) {
            $cnf->save();
        }
    } catch (\Throwable $e) {
        echo 'wan-routes: sync ERROR ' . $e->getMessage() . "\n";
        return false;
    }

    if ($changed && $applyRouting && function_exists('xray_gateways_apply_routing')) {
        xray_gateways_apply_routing();
    } elseif (!$changed) {
        echo "wan-routes: no XML changes\n";
    }
    return $changed;
}

// CLI
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $cmd = $argv[1] ?? 'sync';
    if ($cmd === 'sync') {
        xray_wan_routes_sync_all(true);
    } elseif ($cmd === 'lookup' && isset($argv[2])) {
        $r = xray_wan_routes_nslookup($argv[2]);
        echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Usage: xray-wan-static-routes.php [sync|lookup HOST]\n";
        exit(1);
    }
}
