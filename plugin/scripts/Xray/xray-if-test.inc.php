<?php
/**
 * Persist last IF / Test connect results for Instances grid (cron + manual).
 * Whichever run updates a uuid last wins for that instance.
 */

if (!defined('XRAY_IF_TEST_STATE')) {
    define('XRAY_IF_TEST_STATE', '/var/run/xray_if_test.json');
}

function xray_if_test_load(): array
{
    if (!is_readable(XRAY_IF_TEST_STATE)) {
        return ['updated_at' => 0, 'source' => '', 'instances' => []];
    }
    $j = json_decode((string)file_get_contents(XRAY_IF_TEST_STATE), true);
    if (!is_array($j)) {
        return ['updated_at' => 0, 'source' => '', 'instances' => []];
    }
    if (!isset($j['instances']) || !is_array($j['instances'])) {
        $j['instances'] = [];
    }
    return $j;
}

/**
 * @param array<string,array{result?:string,http_code?:int,message?:string,ok?:bool}> $rows by uuid
 */
function xray_if_test_save_many(array $rows, string $source): void
{
    $cur = xray_if_test_load();
    $now = time();
    foreach ($rows as $uuid => $row) {
        $uuid = (string)$uuid;
        if ($uuid === '') {
            continue;
        }
        $ok = array_key_exists('ok', $row)
            ? !empty($row['ok'])
            : ((string)($row['result'] ?? '') === 'ok');
        $http = (int)($row['http_code'] ?? 0);
        $msg  = trim((string)($row['message'] ?? ''));
        if ($msg === '') {
            $msg = $ok
                ? ("OK ({$http}) — connection works")
                : ($http === 0
                    ? 'Could not connect — xray-core may be stopped or port unreachable'
                    : "HTTP {$http} — unexpected response from 1.1.1.1");
        }
        $cur['instances'][$uuid] = [
            'result'    => $ok ? 'ok' : 'failed',
            'http_code' => $http,
            'message'   => $msg,
            'source'    => $source,
            'tested_at' => $now,
        ];
    }
    $cur['updated_at'] = $now;
    $cur['source']     = $source;
    @file_put_contents(
        XRAY_IF_TEST_STATE,
        json_encode($cur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    @chmod(XRAY_IF_TEST_STATE, 0644);
}

function xray_if_test_save_one(
    string $uuid,
    bool $ok,
    int $http,
    string $message,
    string $source
): void {
    if ($uuid === '') {
        return;
    }
    xray_if_test_save_many(
        [
            $uuid => [
                'ok'        => $ok,
                'http_code' => $http,
                'message'   => $message,
            ],
        ],
        $source
    );
}

/** Normalize SOCKS connect address (0.0.0.0 → 127.0.0.1). */
function xray_socks_connect_host(string $listen): string
{
    $listen = trim($listen);
    return ($listen === '' || $listen === '0.0.0.0') ? '127.0.0.1' : $listen;
}

function xray_socks_port_open(string $listen, int $port, float $timeout = 0.35): bool
{
    if ($port < 1 || $port > 65535) {
        return false;
    }
    $host = xray_socks_connect_host($listen);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!is_resource($fp)) {
        return false;
    }
    fclose($fp);
    return true;
}

/**
 * Wait until SOCKS port accepts TCP (boot / syshook race with xray-core).
 */
function xray_socks_wait_ready(string $listen, int $port, int $maxWaitSec = 12): bool
{
    if ($port < 1 || $port > 65535) {
        $port = 10808;
    }
    $deadline = microtime(true) + max(0, $maxWaitSec);
    do {
        if (xray_socks_port_open($listen, $port)) {
            return true;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);
    return false;
}
