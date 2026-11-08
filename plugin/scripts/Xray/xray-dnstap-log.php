#!/usr/local/bin/php
<?php
/**
 * Tail of dnstap-bgp syslog-ng log (/var/log/dnstap-bgp/dnstap-bgp.log).
 */

$logfile = '/var/log/dnstap-bgp/dnstap-bgp.log';

if (!file_exists($logfile)) {
    echo "(log file not found: {$logfile})\n";
    exit(0);
}

exec('/usr/bin/tail -n 200 ' . escapeshellarg($logfile), $out);
echo implode("\n", $out) . "\n";
