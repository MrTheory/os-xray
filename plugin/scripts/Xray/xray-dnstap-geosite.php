<?php
/**
 * Разбор V2Ray/Xray geosite.dat (protobuf GeoSiteList).
 */

function xray_geosite_pb_varint(string $bin, int &$o, int $end): int
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

function xray_geosite_pb_skip(string $bin, int &$o, int $end, int $wt): void
{
    if ($wt === 0) {
        xray_geosite_pb_varint($bin, $o, $end);
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
        $len = xray_geosite_pb_varint($bin, $o, $end);
        $o += $len;
        if ($o > $end) {
            $o = $end;
        }
    }
}

function xray_geosite_pb_domain(string $bin): string
{
    $o = 0;
    $end = strlen($bin);
    $type = 0;
    $value = '';
    while ($o < $end) {
        $tag = xray_geosite_pb_varint($bin, $o, $end);
        $fn = $tag >> 3;
        $wt = $tag & 7;
        if ($fn === 1 && $wt === 0) {
            $type = xray_geosite_pb_varint($bin, $o, $end);
            continue;
        }
        if ($fn === 2 && $wt === 2) {
            $len = xray_geosite_pb_varint($bin, $o, $end);
            $value = substr($bin, $o, $len);
            $o += $len;
            continue;
        }
        xray_geosite_pb_skip($bin, $o, $end, $wt);
    }
    // 0 Plain, 2 Domain (suffix), 3 Full — в список как FQDN; 1 Regex пропускаем
    if ($type === 1 || $value === '') {
        return '';
    }
    return function_exists('xray_dnstap_normalize_domain')
        ? xray_dnstap_normalize_domain($value)
        : strtolower($value);
}

function xray_geosite_pb_entry(string $bin): array
{
    $o = 0;
    $end = strlen($bin);
    $name = '';
    $domains = [];
    while ($o < $end) {
        $tag = xray_geosite_pb_varint($bin, $o, $end);
        $fn = $tag >> 3;
        $wt = $tag & 7;
        if ($fn === 1 && $wt === 2) {
            $len = xray_geosite_pb_varint($bin, $o, $end);
            $name = strtolower(trim(substr($bin, $o, $len)));
            $o += $len;
            continue;
        }
        if ($fn === 2 && $wt === 2) {
            $len = xray_geosite_pb_varint($bin, $o, $end);
            $d = xray_geosite_pb_domain(substr($bin, $o, $len));
            $o += $len;
            if ($d !== '') {
                $domains[$d] = true;
            }
            continue;
        }
        xray_geosite_pb_skip($bin, $o, $end, $wt);
    }
    return ['name' => $name, 'domains' => $domains];
}

/**
 * @return array<string, array<string, true>> category => set of domains
 */
function xray_geosite_parse_dat(string $bin): array
{
    $o = 0;
    $end = strlen($bin);
    $out = [];
    while ($o < $end) {
        $tag = xray_geosite_pb_varint($bin, $o, $end);
        $fn = $tag >> 3;
        $wt = $tag & 7;
        if ($fn === 1 && $wt === 2) {
            $len = xray_geosite_pb_varint($bin, $o, $end);
            $entry = xray_geosite_pb_entry(substr($bin, $o, $len));
            $o += $len;
            $name = $entry['name'];
            if ($name === '') {
                continue;
            }
            if (!isset($out[$name])) {
                $out[$name] = [];
            }
            foreach ($entry['domains'] as $d => $_) {
                $out[$name][$d] = true;
            }
            continue;
        }
        xray_geosite_pb_skip($bin, $o, $end, $wt);
    }
    ksort($out, SORT_STRING);
    return $out;
}

function xray_geosite_normalize_action(string $action): string
{
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

function xray_geosite_parse_assignments(string $text): array
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }
        if (preg_match('/^(\S+)\s+(\S+)$/', $trim, $m)) {
            $action = xray_geosite_normalize_action($m[2]);
            if ($action !== '') {
                $out[strtolower($m[1])] = $action;
            }
        }
    }
    return $out;
}

function xray_geosite_render_assignments(array $map, string $url = ''): string
{
    $lines = [
        '# geosite category assignments: NAME proxy|direct|block',
        '# Categories without an assignment are ignored.',
    ];
    if ($url !== '') {
        $lines[] = '# source: ' . $url;
    }
    $lines[] = '';
    ksort($map, SORT_STRING);
    foreach ($map as $name => $action) {
        $action = xray_geosite_normalize_action((string)$action);
        if ($action === '') {
            continue;
        }
        $name = strtolower(trim((string)$name));
        if ($name === '' || !preg_match('/^[a-z0-9._-]+$/', $name)) {
            continue;
        }
        $lines[] = $name . ' ' . $action;
    }
    return implode("\n", $lines) . "\n";
}

function xray_geosite_assignments_from_rows(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        $name = strtolower(trim((string)($row['name'] ?? $row['key'] ?? '')));
        $action = xray_geosite_normalize_action((string)($row['action'] ?? $row['value'] ?? ''));
        if ($name === '' || $action === '') {
            continue;
        }
        $map[$name] = $action;
    }
    return $map;
}

function xray_dnstap_geodat_id(string $url): string
{
    return sha1(strtolower(rtrim(trim($url), '/')));
}

function xray_dnstap_geodat_cat_dir(): string
{
    return '/usr/local/etc/dnstap-bgp/geodat-categories';
}

function xray_dnstap_geodat_cat_path(string $url): string
{
    return xray_dnstap_geodat_cat_dir() . '/' . xray_dnstap_geodat_id($url) . '.txt';
}

/**
 * @param array<string, array<string, true>> $cats
 * @param array<string, string> $assign
 * @return array{proxy: string[], direct: string[], block: string[]}
 */
function xray_geosite_collect_domains(array $cats, array $assign): array
{
    $proxy = [];
    $direct = [];
    $block = [];
    foreach ($assign as $name => $action) {
        if (!isset($cats[$name])) {
            continue;
        }
        $action = xray_geosite_normalize_action((string)$action);
        foreach ($cats[$name] as $d => $_) {
            if ($action === 'block') {
                $block[$d] = true;
            } elseif ($action === 'proxy') {
                $proxy[$d] = true;
            } elseif ($action === 'direct') {
                $direct[$d] = true;
            }
        }
    }
    return [
        'proxy'  => array_keys($proxy),
        'direct' => array_keys($direct),
        'block'  => array_keys($block),
    ];
}
