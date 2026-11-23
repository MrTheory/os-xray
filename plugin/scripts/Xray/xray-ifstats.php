#!/usr/local/bin/php
<?php
/**
 * xray-ifstats.php — E4: статистика TUN-интерфейса и процессов для панели Diagnostics.
 *
 * Читает имя TUN-интерфейса из OPNsense config.xml,
 * собирает: IP, bytes in/out, состояние, uptime процессов xray-core и tun2socks.
 * Выводит JSON — ServiceController::diagnosticsAction() декодирует и отдаёт в GUI.
 */

require_once('config.inc');

// ─── Per-instance path helpers (mirrors xray-service-control.php) ────────────
function xray_pid_path(string $uuid): string  { return "/var/run/xray_core_{$uuid}.pid"; }
function t2s_pid_path(string $uuid): string   { return "/var/run/tun2socks_{$uuid}.pid"; }

// ─── Читаем config ────────────────────────────────────────────────────────────
// Принимаем UUID инстанса как первый аргумент (передаётся из actions_xray.conf %1)
$instUuid = isset($argv[1]) ? preg_replace('/[^0-9a-fA-F\-]/', '', trim($argv[1])) : '';

$cfg = OPNsense\Core\Config::getInstance()->object();
$ins = $cfg->OPNsense->xray->instances ?? null;

$inst = null;
if ($ins) {
    foreach ($ins->instance as $candidate) {
        if ($instUuid === '' || (string)$candidate['uuid'] === $instUuid) {
            $inst = $candidate;
            if ($instUuid !== '') break; // точное совпадение — берём его
        }
    }
}

$tunIface = (string)($inst->tun_interface ?? 'proxytun2socks0');

// ─── Uptime процесса по PID-файлу ────────────────────────────────────────────
function proc_uptime(string $pidfile): ?int
{
    if (!file_exists($pidfile)) {
        return null;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return null;
    }
    // etimes= returns elapsed seconds as a plain integer.
    // Returns empty for non-existent PIDs (no kill -0 needed, avoids EPERM for root processes).
    $raw = trim((string)shell_exec('ps -o etimes= -p ' . $pid));
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int)$raw;
}

function format_uptime(?int $secs): string
{
    if ($secs === null) {
        return 'stopped';
    }
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    $m = intdiv($secs % 3600, 60);
    $s = $secs % 60;
    if ($d > 0) {
        return "{$d}d {$h}h {$m}m";
    }
    if ($h > 0) {
        return "{$h}h {$m}m {$s}s";
    }
    return "{$m}m {$s}s";
}

// ─── Статистика TUN-интерфейса через ifconfig ─────────────────────────────────
$ifOut = [];
exec('/sbin/ifconfig ' . escapeshellarg($tunIface) . ' 2>/dev/null', $ifOut, $ifRc);
$ifconfig = implode("\n", $ifOut);

// Парсим ключевые поля
$tunIp     = '';
$tunIp6    = '';
$tunMask   = '';
$tunStatus = 'no carrier';
$bytesIn   = 0;
$bytesOut  = 0;
$pktsIn    = 0;
$pktsOut   = 0;
$mtu       = 0;

if ($ifRc === 0) {
    // inet x.x.x.x netmask 0xFFFFFF00
    if (preg_match('/inet\s+(\S+)\s+netmask\s+(\S+)/', $ifconfig, $m)) {
        $tunIp   = $m[1];
        // netmask может быть hex (0xffffff00) или CIDR — нормализуем в CIDR
        $hexMask = $m[2];
        if (str_starts_with($hexMask, '0x')) {
            $bits    = 0;
            $dec     = hexdec(substr($hexMask, 2));
            for ($i = 31; $i >= 0; $i--) {
                if ($dec & (1 << $i)) {
                    $bits++;
                }
            }
            $tunMask = '/' . $bits;
        } else {
            $tunMask = '/' . $hexMask;
        }
    }
    if (preg_match('/inet6\s+([0-9a-f:]+)(?:%|\s)/i', $ifconfig, $m6)) {
        $prefix = '';
        if (preg_match('/inet6\s+[0-9a-f:]+%?[0-9a-z_]*\s+prefixlen\s+(\d+)/i', $ifconfig, $pm6)) {
            $prefix = '/' . $pm6[1];
        }
        $tunIp6 = $m6[1] . $prefix;
    }
    // flags: UP,RUNNING → статус
    if (preg_match('/flags=\S+<([^>]+)>/', $ifconfig, $m)) {
        $flags     = explode(',', strtolower($m[1]));
        $tunStatus = in_array('running', $flags, true) ? 'running' : 'down';
    }
    // mtu
    if (preg_match('/mtu\s+(\d+)/', $ifconfig, $m)) {
        $mtu = (int)$m[1];
    }
    // bytes in / bytes out (FreeBSD ifconfig format)
    // "        RX bytes:1234567 (1.2 MiB)"  — формат зависит от версии
    // FreeBSD 14: "        bytes 1234567 " в блоке RX/TX
    // Более надёжно: netstat
    $nsOut = [];
    exec('netstat -ibn -I ' . escapeshellarg($tunIface) . ' 2>/dev/null', $nsOut);
    foreach ($nsOut as $line) {
        $parts = preg_split('/\s+/', trim($line));
        // Ищем строку <Link> — только в ней реальные байты/пакеты.
        // FreeBSD формат с Idrop: Name Mtu Network Address Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll
        //                               [0]  [1]  [2]     [3]     [4]   [5]   [6]   [7]    [8]   [9]   [10]  [11]
        // Name column may be truncated for long names; -I already filters to this iface
        if (isset($parts[2]) && strpos($parts[2], '<Link') === 0) {
            // Находим позицию Ipkts по первом целому числу после Network-поля.
            // Address может совпадать с именем интерфейса и содержать пробелы (разные длины имен) —
            // ищем позицию динамически: первое целое число > 0 после Network.
            $ipktsIdx = null;
            for ($pi = 3; $pi < count($parts); $pi++) {
                if (ctype_digit($parts[$pi])) {
                    $ipktsIdx = $pi;
                    break;
                }
            }
            if ($ipktsIdx !== null) {
                // Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll
                $pktsIn   = (int)$parts[$ipktsIdx];
                $bytesIn  = (int)$parts[$ipktsIdx + 3];
                $pktsOut  = (int)$parts[$ipktsIdx + 4];
                $bytesOut = (int)$parts[$ipktsIdx + 6];
            }
            break;
        }
    }
}

// ─── Uptime процессов ─────────────────────────────────────────────────────────
$xrayUptimeSecs = $instUuid !== '' ? proc_uptime(xray_pid_path($instUuid)) : null;
$t2sUptimeSecs  = $instUuid !== '' ? proc_uptime(t2s_pid_path($instUuid))  : null;

// ─── Ping RTT до VPN-сервера ─────────────────────────────────────────────────
$outboundJson = (string)($inst->outbound_config ?? '');
$outboundArr  = json_decode($outboundJson, true);
$serverAddr   = $outboundArr['settings']['vnext'][0]['address'] ?? '';
$pingRtt = 'N/A';
if ($serverAddr !== '') {
    exec('/sbin/ping -c 3 -W 2 ' . escapeshellarg($serverAddr) . ' 2>/dev/null', $pingOut, $pingRc);
    if ($pingRc === 0) {
        // Ищем "round-trip min/avg/max/stddev = 1.234/2.345/3.456/0.567 ms"
        $pingOutput = implode("\n", $pingOut);
        if (preg_match('/round-trip.+=\s*[\d.]+\/([\d.]+)\//', $pingOutput, $pm)) {
            $pingRtt = $pm[1] . ' ms';
        }
    }
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
}

$useIpv4 = true;
$useIpv6 = false;
$ipStack = 'IPv4';
$dnsServers = '1.1.1.1,8.8.8.8';
if ($inst !== null) {
    $useIpv4 = (string)($inst->use_ipv4 ?? '1') === '1';
    $useIpv6 = (string)($inst->use_ipv6 ?? '0') === '1';
    $ipStack = ($useIpv4 && $useIpv6) ? 'IPv4+IPv6' : ($useIpv6 ? 'IPv6' : 'IPv4');
    $dnsServers = trim((string)($inst->dns_servers ?? '1.1.1.1,8.8.8.8')) ?: '1.1.1.1,8.8.8.8';
}

// ─── Сборка результата ────────────────────────────────────────────────────────
$result = [
    'tun_interface' => $tunIface,
    'tun_status'    => $tunIface !== '' ? $tunStatus : 'no interface configured',
    'tun_ip'        => $tunIp ? ($tunIp . $tunMask) : '',
    'tun_ip6'       => $tunIp6,
    'ip_stack'      => $ipStack,
    'dns_servers'   => $dnsServers,
    'mtu'           => $mtu,
    'bytes_in'      => $bytesIn,
    'bytes_out'     => $bytesOut,
    'pkts_in'       => $pktsIn,
    'pkts_out'      => $pktsOut,
    'bytes_in_hr'   => format_bytes($bytesIn),
    'bytes_out_hr'  => format_bytes($bytesOut),
    'xray_uptime'       => format_uptime($xrayUptimeSecs),
    'xray_uptime_secs'  => $xrayUptimeSecs,
    'tun2socks_uptime'      => format_uptime($t2sUptimeSecs),
    'tun2socks_uptime_secs' => $t2sUptimeSecs,
    'server_address'        => $serverAddr,
    'ping_rtt'              => $pingRtt,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
