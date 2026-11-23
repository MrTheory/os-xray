#!/usr/local/bin/php
<?php
/**
 * Tail of BIRD syslog-ng log (/var/log/bird/bird.log).
 */

$logfile = '/var/log/bird/bird.log';

if (!file_exists($logfile)) {
    echo "(log file not found: {$logfile})\n";
    exit(0);
}

exec('/usr/bin/tail -n 200 ' . escapeshellarg($logfile), $out);
echo implode("\n", $out) . "\n";
