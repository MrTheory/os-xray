#!/usr/local/bin/php
<?php
/**
 * xray-testconnect.php — BUG-4 FIX: тест подключения через SOCKS5-прокси.
 *
 * До исправления: порт 10808 был захардкожен в actions_xray.conf:
 *   parameters:--socks5 127.0.0.1:10808 -s -L ...
 * Проблема: если пользователь изменил socks5_port в GUI, тест продолжал
 * стучаться на старый порт и всегда возвращал ошибку подключения.
 *
 * После: читаем socks5_port из config.xml — тест всегда использует актуальный порт.
 */

require_once('config.inc');
require_once(__DIR__ . '/xray-boot-log.inc.php');
require_once(__DIR__ . '/xray-if-test.inc.php');

// UUID инстанса как первый аргумент (из actions_xray.conf %1)
$instUuid = isset($argv[1]) ? preg_replace('/[^0-9a-fA-F\-]/', '', trim($argv[1])) : '';

$cfg = OPNsense\Core\Config::getInstance()->object();
$ins = $cfg->OPNsense->xray->instances ?? null;

$inst = null;
if ($ins) {
    foreach ($ins->instance as $candidate) {
        if ($instUuid === '' || (string)$candidate['uuid'] === $instUuid) {
            $inst = $candidate;
            if ($instUuid !== '') break;
        }
    }
}

// Читаем адрес и порт из конфига найденного инстанса
$listen = (string)($inst->socks5_listen ?? '127.0.0.1') ?: '127.0.0.1';
$port   = (int)(string)($inst->socks5_port ?? 10808);

// Защита: порт должен быть в допустимом диапазоне
if ($port < 1 || $port > 65535) {
    $port = 10808;
}

// 0.0.0.0 слушает на всех интерфейсах — для теста подключаемся на loopback
$proxy   = xray_socks_connect_host($listen) . ':' . $port;
$target  = 'https://1.1.1.1';
$timeout = '10';

xray_socks_wait_ready($listen, $port, 10);
$code = '0';
$rc = 1;
for ($i = 0; $i < 3; $i++) {
    $out = [];
    exec(
        '/usr/local/bin/curl'
        . ' --socks5 ' . escapeshellarg($proxy)
        . ' -s -L -o /dev/null'
        . ' -w %{http_code}'
        . ' ' . escapeshellarg($target)
        . ' --max-time ' . $timeout,
        $out,
        $rc
    );
    $code = implode('', $out);
    $httpTry = (int)$code;
    if ($rc === 0 && $httpTry >= 200 && $httpTry < 400) {
        break;
    }
    if ($httpTry !== 0) {
        break;
    }
    usleep(500000);
    xray_socks_wait_ready($listen, $port, 3);
}
echo $code . "\n";

$http = (int)$code;
$ok   = ($http >= 200 && $http < 400);
$name = $inst ? (string)($inst->name ?? $instUuid) : ($instUuid !== '' ? $instUuid : '(none)');
$tun  = $inst ? trim((string)($inst->tun_interface ?? '')) : '';
if ($tun === '') {
    $tun = 'proxytun2socks0';
}
$en = $inst && (string)($inst->enabled ?? '1') === '1' ? '1' : '0';
$uuidSave = $instUuid !== '' ? $instUuid : (string)($inst['uuid'] ?? '');
$msg = $ok
    ? "OK ({$http}) — connection works"
    : ($http === 0
        ? 'Could not connect — xray-core may be stopped or port unreachable'
        : "HTTP {$http} — unexpected response from 1.1.1.1");
if ($uuidSave !== '') {
    xray_if_test_save_one($uuidSave, $ok, $http, $msg, 'manual');
}
xray_boot_log_block(
    'Test connect',
    "instance={$name} uuid=" . ($instUuid !== '' ? $instUuid : '(first)') . "\n"
    . "enabled={$en} tun={$tun} socks={$proxy}\n"
    . "check: HTTP " . ($code !== '' ? $code : '0') . ($ok ? ' ok' : ' failed')
    . " target={$target}"
);

exit($rc);
