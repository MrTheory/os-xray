#!/usr/local/bin/php
<?php
/**
 * xray-watchdog.php — E1: watchdog для всех инстансов xray-core + tun2socks.
 *
 * v3.0.0: итерирует все инстансы из config.xml; для каждого инстанса:
 *   1. Проверяет watchdog_enabled (общий флаг) и xray_stopped_<uuid>.flag (намеренная остановка).
 *   2. Проверяет PID-файлы инстанса.
 *   3. Если xray-core ИЛИ tun2socks упал — вызывает restart <inst_uuid>.
 *
 * Вызывается из cron каждую минуту через configd action [watchdog].
 */

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

define('XRAY_CTRL',    '/usr/local/opnsense/scripts/Xray/xray-service-control.php');
define('WATCHDOG_LOG', '/var/log/xray-watchdog.log');

function wlog(string $msg): void
{
    $ts   = date('Y-m-d H:i:s');
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

// ─── Читаем конфиг ────────────────────────────────────────────────────────────
$cfg     = OPNsense\Core\Config::getInstance()->object();
$general = $cfg->OPNsense->xray->general ?? null;

$enabled         = (string)($general->enabled          ?? '0') === '1';
$watchdogEnabled = (string)($general->watchdog_enabled ?? '0') === '1';

if (!$enabled) {
    exit(0);
}

if (!$watchdogEnabled) {
    exit(0);
}

$instances = $cfg->OPNsense->xray->instances ?? null;
if (!$instances) {
    exit(0);
}

if (!file_exists(XRAY_CTRL)) {
    wlog('ERROR: ' . XRAY_CTRL . ' not found — cannot restart');
    exit(1);
}

// ─── Итерируем все инстансы ───────────────────────────────────────────────────
$anyFailed = false;

foreach ($instances->instance as $inst) {
    $inst_uuid = (string)$inst['uuid'];
    if ($inst_uuid === '') {
        continue;
    }

    $name = (string)($inst->name ?? $inst_uuid);

    // БАГ-5 FIX: если инстанс намеренно остановлен — не трогаем
    $stoppedFlag = "/var/run/xray_stopped_{$inst_uuid}.flag";
    if (file_exists($stoppedFlag)) {
        continue;
    }

    $xrayPid = "/var/run/xray_core_{$inst_uuid}.pid";
    $t2sPid  = "/var/run/tun2socks_{$inst_uuid}.pid";

    $xrayAlive = proc_alive($xrayPid);
    $t2sAlive  = proc_alive($t2sPid);

    // E1: watchdog must check xray-core PID (XRAY_PID) and tun2socks PID (T2S_PID)
    if ($xrayAlive && $t2sAlive) {
        continue; // всё в порядке
    }

    $died = [];
    if (!$xrayAlive) $died[] = 'xray-core';
    if (!$t2sAlive)  $died[] = 'tun2socks';

    wlog("WATCHDOG [{$name}]: " . implode(', ', $died) . " not running — triggering restart");

    exec('/usr/local/bin/php ' . escapeshellarg(XRAY_CTRL) . ' restart ' . escapeshellarg($inst_uuid) . ' 2>&1', $out, $rc);
    $output = trim(implode("\n", $out));

    if ($rc === 0) {
        wlog("WATCHDOG [{$name}]: restart OK — " . ($output ?: 'no output'));
    } else {
        wlog("WATCHDOG [{$name}]: restart FAILED (exit {$rc}) — {$output}");
        $anyFailed = true;
    }
}

exit($anyFailed ? 1 : 0);
