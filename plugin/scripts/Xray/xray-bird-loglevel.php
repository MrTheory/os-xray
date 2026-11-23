#!/usr/local/bin/php
<?php
/**
 * Read or set BIRD syslog log class in /usr/local/etc/bird.conf, then birdc configure.
 *
 *   xray-bird-loglevel.php get
 *   xray-bird-loglevel.php set warning
 */

const XRAY_BIRD_CONF = '/usr/local/etc/bird.conf';
const XRAY_BIRD_LOG_DEFAULT = 'warning';
const XRAY_BIRD_LOG_CLASSES = [
    'debug', 'trace', 'info', 'remote', 'warning', 'error', 'auth', 'fatal', 'bug', 'all',
];

function xray_bird_log_line(string $level): string
{
    if ($level === 'all') {
        return 'log syslog all;';
    }
    return 'log syslog { ' . $level . ' };';
}

function xray_bird_log_parse(string $text): string
{
    if (preg_match('/^\s*log\s+syslog\s+all\s*;/mi', $text)) {
        return 'all';
    }
    if (preg_match('/^\s*log\s+syslog\s+\{\s*([a-z]+)\s*\}\s*;/mi', $text, $m)) {
        $level = strtolower($m[1]);
        if (in_array($level, XRAY_BIRD_LOG_CLASSES, true)) {
            return $level;
        }
    }
    if (preg_match('/^\s*log\s+syslog\s+([a-z]+)\s*;/mi', $text, $m)) {
        $level = strtolower($m[1]);
        if (in_array($level, XRAY_BIRD_LOG_CLASSES, true)) {
            return $level;
        }
    }
    return XRAY_BIRD_LOG_DEFAULT;
}

function xray_bird_log_write(string $level): bool
{
    $line = xray_bird_log_line($level);
    $text = is_readable(XRAY_BIRD_CONF) ? (string)file_get_contents(XRAY_BIRD_CONF) : '';
    if ($text === '') {
        $text = $line . "\n";
    } elseif (preg_match('/^\s*log\s+syslog\s+.+$/mi', $text)) {
        $text = preg_replace('/^\s*log\s+syslog\s+.+$/mi', $line, $text, 1);
    } else {
        $text = $line . "\n" . $text;
    }
    return file_put_contents(XRAY_BIRD_CONF, $text) !== false;
}

function xray_bird_log_configure(): string
{
    $out = [];
    exec('/usr/sbin/service bird status 2>&1', $out, $rc);
    $status = strtolower(implode("\n", $out));
    if (strpos($status, 'not running') !== false || !is_executable('/usr/local/sbin/birdc')) {
        return 'bird not running';
    }
    $cfg = [];
    exec('/usr/local/sbin/birdc configure 2>&1', $cfg, $crc);
    return trim(implode("\n", $cfg));
}

$op    = strtolower(trim((string)($argv[1] ?? 'get')));
$level = strtolower(trim((string)($argv[2] ?? '')));
if ($op !== 'get' && $op !== 'set') {
    if (in_array($op, XRAY_BIRD_LOG_CLASSES, true)) {
        $level = $op;
        $op    = 'set';
    } else {
        $op = 'get';
    }
}

if ($op === 'set') {
    if (!in_array($level, XRAY_BIRD_LOG_CLASSES, true)) {
        echo json_encode([
            'result'  => 'failed',
            'message' => 'Invalid log class',
            'level'   => XRAY_BIRD_LOG_DEFAULT,
        ]) . "\n";
        exit(1);
    }
    if (!xray_bird_log_write($level)) {
        echo json_encode([
            'result'  => 'failed',
            'message' => 'Cannot write ' . XRAY_BIRD_CONF,
            'level'   => $level,
        ]) . "\n";
        exit(1);
    }
    $cfg = xray_bird_log_configure();
    echo json_encode([
        'result'    => 'ok',
        'level'     => $level,
        'configure' => $cfg,
    ]) . "\n";
    exit(0);
}

$text = is_readable(XRAY_BIRD_CONF) ? (string)file_get_contents(XRAY_BIRD_CONF) : '';
echo json_encode([
    'result' => 'ok',
    'level'  => xray_bird_log_parse($text),
]) . "\n";
