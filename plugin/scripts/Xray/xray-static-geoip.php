<?php
/**
 * Разбор V2Ray/Xray geoip.dat (protobuf GeoIPList) → категории → CIDR IPv4/IPv6.
 */

function xray_geoip_pb_varint(string $bin, int &$o, int $end): int
{
    $r = 0;
    $s = 0;
    while ($o < $end) {
        $b = ord($bin[$o]);
        $o++;
        $r |= ($b & 0x7f) << $s;
        if (($b & 0x80) === 0) {
            return $r;
        }
        $s += 7;
        if ($s > 63) {
            break;
        }
    }
    return $r;
}

function xray_geoip_pb_skip(string $bin, int &$o, int $end, int $wt): void
{
    if ($wt === 0) {
        xray_geoip_pb_varint($bin, $o, $end);
        return;
    }
    if ($wt === 1) {
        $o += 8;
        if ($o > $end) {
            $o = $end;
        }
        return;
    }
    if ($wt === 5) {
        $o += 4;
        if ($o > $end) {
            $o = $end;
        }
        return;
    }
    if ($wt === 2) {
        $len = xray_geoip_pb_varint($bin, $o, $end);
        $o += $len;
        if ($o > $end) {
            $o = $end;
        }
    }
}

/**
 * @return array{cidr:string,v6:bool}|null
 */
function xray_geoip_pb_cidr(string $bin): ?array
{
    $o = 0;
    $end = strlen($bin);
    $ip = '';
    $prefix = -1;
    while ($o < $end) {
        $tag = xray_geoip_pb_varint($bin, $o, $end);
        $fn = $tag >> 3;
        $wt = $tag & 7;
        if ($fn === 1 && $wt === 2) {
            $len = xray_geoip_pb_varint($bin, $o, $end);
            $ip = substr($bin, $o, $len);
            $o += $len;
            continue;
        }
        if ($fn === 2 && $wt === 0) {
            $prefix = xray_geoip_pb_varint($bin, $o, $end);
            continue;
        }
        xray_geoip_pb_skip($bin, $o, $end, $wt);
    }
    if ($ip === '' || $prefix < 0) {
        return null;
    }
    $len = strlen($ip);
    if ($len === 4) {
        if ($prefix > 32) {
            return null;
        }
        $addr = inet_ntop($ip);
        if ($addr === false) {
            return null;
        }
        return ['cidr' => $addr . '/' . $prefix, 'v6' => false];
    }
    if ($len === 16) {
        if ($prefix > 128) {
            return null;
        }
        $addr = inet_ntop($ip);
        if ($addr === false) {
            return null;
        }
        return ['cidr' => $addr . '/' . $prefix, 'v6' => true];
    }
    return null;
}

/**
 * @return array{name:string,v4:array<string,true>,v6:array<string,true>}
 */
function xray_geoip_pb_entry(string $bin): array
{
    $o = 0;
    $end = strlen($bin);
    $name = '';
    $v4 = [];
    $v6 = [];
    while ($o < $end) {
        $tag = xray_geoip_pb_varint($bin, $o, $end);
        $fn = $tag >> 3;
        $wt = $tag & 7;
        if ($fn === 1 && $wt === 2) {
            $len = xray_geoip_pb_varint($bin, $o, $end);
            $name = strtolower(trim(substr($bin, $o, $len)));
            $o += $len;
            continue;
        }
        if ($fn === 2 && $wt === 2) {
            $len = xray_geoip_pb_varint($bin, $o, $end);
            $cidr = xray_geoip_pb_cidr(substr($bin, $o, $len));
            $o += $len;
            if ($cidr !== null) {
                if ($cidr['v6']) {
                    $v6[$cidr['cidr']] = true;
                } else {
                    $v4[$cidr['cidr']] = true;
                }
            }
            continue;
        }
        xray_geoip_pb_skip($bin, $o, $end, $wt);
    }
    return ['name' => $name, 'v4' => $v4, 'v6' => $v6];
}

/**
 * @return array<string, array{v4:array<string,true>,v6:array<string,true>,count:int}>
 */
function xray_geoip_parse_dat(string $bin): array
{
    $o = 0;
    $end = strlen($bin);
    $out = [];
    while ($o < $end) {
        $tag = xray_geoip_pb_varint($bin, $o, $end);
        $fn = $tag >> 3;
        $wt = $tag & 7;
        if ($fn === 1 && $wt === 2) {
            $len = xray_geoip_pb_varint($bin, $o, $end);
            $entry = xray_geoip_pb_entry(substr($bin, $o, $len));
            $o += $len;
            $name = $entry['name'];
            if ($name === '') {
                continue;
            }
            if (!isset($out[$name])) {
                $out[$name] = ['v4' => [], 'v6' => [], 'count' => 0];
            }
            foreach ($entry['v4'] as $c => $_) {
                $out[$name]['v4'][$c] = true;
            }
            foreach ($entry['v6'] as $c => $_) {
                $out[$name]['v6'][$c] = true;
            }
            $out[$name]['count'] = count($out[$name]['v4']) + count($out[$name]['v6']);
            continue;
        }
        xray_geoip_pb_skip($bin, $o, $end, $wt);
    }
    ksort($out, SORT_STRING);
    return $out;
}

function xray_geoip_normalize_action(string $action): string
{
    if (function_exists('xray_geosite_normalize_action')) {
        return xray_geosite_normalize_action($action);
    }
    $action = strtolower(trim($action));
    if ($action === 'proxy' || $action === 'blocked') {
        return 'proxy';
    }
    if ($action === 'direct' || $action === 'unblocked') {
        return 'direct';
    }
    if ($action === 'block') {
        return 'block';
    }
    return '';
}

/**
 * @param array<string, array{v4:array<string,true>,v6:array<string,true>,count?:int}> $cats
 * @param array<string, string> $assign
 * @return array{proxy4:string[],proxy6:string[],direct4:string[],direct6:string[],block4:string[],block6:string[]}
 */
function xray_geoip_collect_prefixes(array $cats, array $assign): array
{
    $out = [
        'proxy4'  => [],
        'proxy6'  => [],
        'direct4' => [],
        'direct6' => [],
        'block4'  => [],
        'block6'  => [],
    ];
    // Build case-insensitive cat index (assignments are lowercased)
    $catsByLower = [];
    foreach ($cats as $cname => $cinfo) {
        $catsByLower[strtolower((string)$cname)] = $cinfo;
    }
    foreach ($assign as $name => $action) {
        $lk = strtolower((string)$name);
        if (!isset($catsByLower[$lk])) {
            continue;
        }
        $action = xray_geoip_normalize_action((string)$action);
        if ($action === '') {
            continue;
        }
        $key4 = $action . '4';
        $key6 = $action . '6';
        foreach ($catsByLower[$lk]['v4'] as $c => $_) {
            $out[$key4][$c] = true;
        }
        foreach ($catsByLower[$lk]['v6'] as $c => $_) {
            $out[$key6][$c] = true;
        }
    }
    return [
        'proxy4'  => array_keys($out['proxy4']),
        'proxy6'  => array_keys($out['proxy6']),
        'direct4' => array_keys($out['direct4']),
        'direct6' => array_keys($out['direct6']),
        'block4'  => array_keys($out['block4']),
        'block6'  => array_keys($out['block6']),
    ];
}
