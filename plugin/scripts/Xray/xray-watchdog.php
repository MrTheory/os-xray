#!/usr/local/bin/php
<?php
/**
 * xray-watchdog.php — E1: watchdog для xray-core и tun2socks.
 *
 * Вызывается из cron каждые N минут (по умолчанию 1).
 * Проверяет состояние процессов; если один из них упал — перезапускает оба.
 *
 * Логика:
 *   1. Читает watchdog_enabled из config.xml — если 0, выходит без действий.
 *   2. Читает PID-файлы и проверяет что процессы живы.
 *   3. Если xray-core ИЛИ tun2socks не запущен — вызывает xray-service-control.php restart.
 *   4. Записывает результат в лог /var/log/xray-watchdog.log.
 *
 * Почему restart, а не start:
 *   - Частичный сбой (только один процесс упал) оставляет систему в невалидном состоянии.
 *   - tun2socks без xray-core будет пытаться проксировать трафик в никуда.
 *   - restart атомарно останавливает и заново поднимает оба процесса.
 */

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

define('XRAY_PID',     '/var/run/xray_core.pid');
define('T2S_PID',      '/var/run/tun2socks.pid');
define('XRAY_CTRL',    '/usr/local/opnsense/scripts/Xray/xray-service-control.php');
define('WATCHDOG_LOG',      '/var/log/xray-watchdog.log');
define('XRAY_STOPPED_FLAG', '/var/run/xray_stopped.flag');

function wlog(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents(WATCHDOG_LOG, $line, FILE_APPEND | LOCK_EX);
}

function proc_alive(string $pidfile): bool
{
    if (!file_exists($pidfile)) {
        return false;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return false;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
    return $rc === 0;
}

// ─── Читаем конфиг ───────────────────────────────────────────────────────────
$cfg      = OPNsense\Core\Config::getInstance()->object();
$general  = $cfg->OPNsense->xray->general ?? null;

$enabled          = (string)($general->enabled          ?? '0') === '1';
$watchdogEnabled  = (string)($general->watchdog_enabled ?? '0') === '1';

if (!$enabled) {
    // Xray глобально отключён — watchdog не должен его запускать
    exit(0);
}

if (!$watchdogEnabled) {
    // Watchdog отключён пользователем явно
    exit(0);
}

// БАГ-5 FIX: если сервис был намеренно остановлен через GUI/stop — не перезапускать
if (file_exists(XRAY_STOPPED_FLAG)) {
    exit(0);
}

// ─── Проверяем процессы ───────────────────────────────────────────────────────
$xrayAlive = proc_alive(XRAY_PID);
$t2sAlive  = proc_alive(T2S_PID);

if ($xrayAlive && $t2sAlive) {
    // Всё в порядке — молча выходим (не засоряем лог)
    exit(0);
}

// ─── Один или оба процесса упали — перезапускаем ─────────────────────────────
$died = [];
if (!$xrayAlive) {
    $died[] = 'xray-core';
}
if (!$t2sAlive) {
    $died[] = 'tun2socks';
}

wlog('WATCHDOG: ' . implode(', ', $died) . ' not running — triggering restart');

if (!file_exists(XRAY_CTRL)) {
    wlog('ERROR: ' . XRAY_CTRL . ' not found — cannot restart');
    exit(1);
}

exec('/usr/local/bin/php ' . escapeshellarg(XRAY_CTRL) . ' restart 2>&1', $out, $rc);
$output = trim(implode("\n", $out));

if ($rc === 0) {
    wlog('WATCHDOG: restart OK — ' . ($output ?: 'no output'));
} else {
    wlog('WATCHDOG: restart FAILED (exit ' . $rc . ') — ' . $output);
}

exit($rc);
