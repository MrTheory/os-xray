<?php
/**
 * Дозапись в Boot / Autoroute Log GUI (/var/log, ротация newsyslog + soft ~50 KB).
 */

if (!defined('XRAY_BOOT_LOG')) {
    define('XRAY_BOOT_LOG', '/var/log/xray-boot.log');
}
if (!defined('XRAY_AUTOROUTE_LOG')) {
    define('XRAY_AUTOROUTE_LOG', '/var/log/xray-autoroute.log');
}
if (!defined('XRAY_BOOT_LOG_MAX')) {
    define('XRAY_BOOT_LOG_MAX', 50000);
}
/** Boot/light mode: start daemons only; defer SOCKS probes + birdsync. */
if (!defined('XRAY_BOOT_LIGHT_FLAG')) {
    define('XRAY_BOOT_LIGHT_FLAG', '/var/run/xray_boot_light');
}
if (!defined('XRAY_BIRDSYNC_DEFERRED_PID')) {
    define('XRAY_BIRDSYNC_DEFERRED_PID', '/var/run/xray_birdsync_deferred.pid');
}
if (!defined('XRAY_BIRDSYNC_DEFERRED_DELAY')) {
    define('XRAY_BIRDSYNC_DEFERRED_DELAY', 45);
}
if (!defined('XRAY_BIRDSYNC_POSTBOOT_TRIES')) {
    define('XRAY_BIRDSYNC_POSTBOOT_TRIES', '/var/run/xray_birdsync_postboot_tries');
}
if (!defined('XRAY_BIRDSYNC_POSTBOOT_MAX_TRIES')) {
    define('XRAY_BIRDSYNC_POSTBOOT_MAX_TRIES', 5);
}

function xray_log_file_rotate(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $size = @filesize($path);
    if ($size !== false && $size > XRAY_BOOT_LOG_MAX) {
        @rename($path, $path . '.0');
    }
}

function xray_boot_log_rotate(): void
{
    xray_log_file_rotate(XRAY_BOOT_LOG);
}

function xray_log_file_append(string $path, string $text): void
{
    xray_log_file_rotate($path);
    $text = rtrim($text, "\n") . "\n";
    @file_put_contents($path, $text, FILE_APPEND | LOCK_EX);
}

function xray_boot_log(string $text): void
{
    xray_log_file_append(XRAY_BOOT_LOG, $text);
}

function xray_log_file_block(string $path, string $title, string $body): void
{
    $body = trim($body);
    if ($body === '') {
        return;
    }
    xray_log_file_append(
        $path,
        "\n=== {$title}: " . date('Y-m-d H:i:s') . " ===\n"
        . $body . "\n=== end ===\n"
    );
}

function xray_boot_log_block(string $title, string $body): void
{
    xray_log_file_block(XRAY_BOOT_LOG, $title, $body);
}

function xray_autoroute_log_block(string $title, string $body): void
{
    xray_log_file_block(XRAY_AUTOROUTE_LOG, $title, $body);
}

/**
 * True while our light flag is set (syshook / start_boot → cleared in birdsync_postboot).
 * Do NOT key off /var/run/booting here: OPNsense keeps that file until the whole boot
 * finishes (often well past the deferred postboot), which would skip SOCKS forever
 * during postboot + early cron (Auto route / IF health).
 */
function xray_boot_light_active(): bool
{
    return is_file(XRAY_BOOT_LIGHT_FLAG);
}

/** OPNsense still in early boot — used only to *enter* light mode / schedule defer. */
function xray_opnsense_booting(): bool
{
    return is_file('/var/run/booting');
}

function xray_boot_light_begin(): void
{
    @file_put_contents(XRAY_BOOT_LIGHT_FLAG, (string)time() . "\n");
    @chmod(XRAY_BOOT_LIGHT_FLAG, 0644);
    if (defined('XRAY_BIRDSYNC_POSTBOOT_TRIES')) {
        @unlink(XRAY_BIRDSYNC_POSTBOOT_TRIES);
    }
}

function xray_boot_light_end(): void
{
    @unlink(XRAY_BOOT_LIGHT_FLAG);
}

/**
 * True if any enabled instance has a live tun2socks PID.
 */
function xray_boot_any_tun2socks_up(): bool
{
    $pattern = '/var/run/tun2socks_*.pid';
    foreach (glob($pattern) ?: [] as $pidFile) {
        $pid = (int)trim((string)@file_get_contents($pidFile));
        if ($pid <= 0) {
            continue;
        }
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
        if ($rc === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Poll until tun2socks is up or timeout. Returns true if ready.
 */
function xray_boot_wait_tun2socks(int $timeoutSec = 90, int $stepSec = 2): bool
{
    $deadline = time() + max(1, $timeoutSec);
    $stepSec = max(1, $stepSec);
    while (true) {
        if (xray_boot_any_tun2socks_up()) {
            echo "birdsync_postboot: tun2socks ready\n";
            return true;
        }
        if (time() >= $deadline) {
            echo "birdsync_postboot: tun2socks not up after {$timeoutSec}s\n";
            return false;
        }
        sleep($stepSec);
    }
}

/**
 * Schedule a single deferred birdsync (and post-boot filter reload) after $delaySec.
 * Deduped via pid file so N instance starts / boot hooks share one job.
 */
function xray_bird_sync_deferred(int $delaySec = 0): void
{
    if ($delaySec <= 0) {
        $delaySec = (int)XRAY_BIRDSYNC_DEFERRED_DELAY;
    }
    $pidFile = XRAY_BIRDSYNC_DEFERRED_PID;
    if (is_readable($pidFile)) {
        $old = (int)trim((string)file_get_contents($pidFile));
        if ($old > 0) {
            exec('/bin/kill -0 ' . $old . ' 2>/dev/null', $out, $rc);
            if ($rc === 0) {
                echo "bird sync: deferred already scheduled (pid {$old})\n";
                return;
            }
        }
    }
    $php = '/usr/local/bin/php';
    $script = '/usr/local/opnsense/scripts/Xray/xray-service-control.php';
    if (!is_readable($script)) {
        $script = __DIR__ . '/xray-service-control.php';
    }
    $cmd = sprintf(
        '( /bin/sleep %d; /usr/bin/nice -n 10 %s %s birdsync_postboot >>%s 2>&1 ) >/dev/null 2>&1 & echo $!',
        $delaySec,
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg(XRAY_BOOT_LOG)
    );
    $pid = trim((string)shell_exec($cmd));
    if ($pid !== '' && ctype_digit($pid)) {
        @file_put_contents($pidFile, $pid . "\n");
        @chmod($pidFile, 0644);
        echo "bird sync: deferred in {$delaySec}s (pid {$pid})\n";
        xray_boot_log("bird sync: deferred in {$delaySec}s (pid {$pid})");
    } else {
        echo "bird sync: WARN could not schedule deferred birdsync\n";
    }
}
