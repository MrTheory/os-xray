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

define('XRAY_PID', '/var/run/xray_core.pid');
define('T2S_PID',  '/var/run/tun2socks.pid');

// ─── Читаем config ────────────────────────────────────────────────────────────
$cfg  = OPNsense\Core\Config::getInstance()->object();
$inst = $cfg->OPNsense->xray->instance ?? null;
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
    // Проверяем что процесс жив
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
        return null;
    }
    // FreeBSD: /proc/$pid/status не всегда смонтирован.
    // Используем ps -o etime= для получения прошедшего времени (формат [[DD-]HH:]MM:SS).
    $etime = trim((string)shell_exec('ps -o etime= -p ' . $pid . ' 2>/dev/null'));
    if (empty($etime)) {
        return null;
    }
    // Парсим etime: [[DD-]HH:]MM:SS → секунды
    $parts  = explode(':', strrev($etime)); // обратный порядок: SS, MM, HH, DD-...
    $secs   = (int)strrev($parts[0] ?? '0');
    $mins   = (int)strrev($parts[1] ?? '0');
    $hrs    = (int)strrev($parts[2] ?? '0');
    $days   = 0;
    if (isset($parts[3])) {
        $dayPart = strrev($parts[3]);
        $dashPos = strpos($dayPart, '-');
        if ($dashPos !== false) {
            $days = (int)substr($dayPart, 0, $dashPos);
            $hrs  = (int)substr($dayPart, $dashPos + 1);
        }
    }
    return $days * 86400 + $hrs * 3600 + $mins * 60 + $secs;
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
        if ($parts[0] === $tunIface && isset($parts[2]) && strpos($parts[2], '<Link') === 0) {
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
$xrayUptimeSecs = proc_uptime(XRAY_PID);
$t2sUptimeSecs  = proc_uptime(T2S_PID);

// ─── Сборка результата ────────────────────────────────────────────────────────
$result = [
    'tun_interface' => $tunIface,
    'tun_status'    => $tunIface !== '' ? $tunStatus : 'no interface configured',
    'tun_ip'        => $tunIp ? ($tunIp . $tunMask) : '',
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
];

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

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
