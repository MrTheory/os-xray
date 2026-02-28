<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Service control: start / stop / reconfigure / status / log / testconnect.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\OPNsense\Xray\General';
    protected static $internalServiceTemplate = 'OPNsense/Xray';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'xray';

    /**
     * BUG-12 FIX: возвращает реальный статус выполнения reconfigure.
     *
     * До исправления: проверялась только наличие строки "ERROR" в выводе.
     * Проблема: пустой ответ (configd завис или timeout) возвращал "ok".
     * После: проверяем наличие маркера успеха "OK" и отсутствие маркеров ошибки.
     * Также проверяем что вывод не пустой — пустой ответ = нет связи с configd.
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $backend = new Backend();
        $output  = trim($backend->configdRun('xray reconfigure'));

        // Пустой ответ — configd не ответил или вернул timeout
        if (empty($output)) {
            return ['result' => 'failed', 'message' => 'No response from configd (timeout or service unavailable)'];
        }

        // Маркеры ошибки: ERROR, failed, или отсутствие маркера OK
        // xray-service-control.php при успехе выводит "OK" (см. case 'reconfigure':)
        $hasError   = stripos($output, 'ERROR')   !== false
                   || stripos($output, 'failed')  !== false;
        $hasSuccess = stripos($output, 'OK')      !== false
                   || stripos($output, 'disabled') !== false; // сервис отключён — тоже корректно

        if ($hasError || !$hasSuccess) {
            return ['result' => 'failed', 'message' => $output];
        }

        return ['result' => 'ok', 'message' => $output];
    }

    public function statusAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray status');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => trim($result)];
    }

    /**
     * E2: POST /api/xray/service/start
     * Запускает xray-core и tun2socks.
     */
    public function startAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = trim($backend->configdRun('xray start'));
        $failed  = empty($output)
                || stripos($output, 'ERROR') !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * E2: POST /api/xray/service/stop
     * Останавливает xray-core и tun2socks, разрушает TUN-интерфейс.
     */
    public function stopAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = trim($backend->configdRun('xray stop'));
        // stop почти всегда успешен; ошибка только если configd не ответил
        return [
            'result'  => empty($output) ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * E2: POST /api/xray/service/restart
     * Перезапускает сервисы без записи конфига (в отличие от reconfigure).
     */
    public function restartAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = trim($backend->configdRun('xray restart'));
        $failed  = empty($output)
                || stripos($output, 'ERROR') !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * BUG-5 FIX: POST /api/xray/service/log
     *
     * До исправления: action принимал GET без ограничений — любой авторизованный
     * пользователь мог получить лог через прямой GET-запрос или CSRF-атаку.
     * Лог содержит IP-адреса серверов, имена интерфейсов, фрагменты ключей Reality.
     * После: только POST. Вызовы ajaxGet() → $.post() в general.volt (loadLog).
     */
    public function logAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = $backend->configdRun('xray log');
        return ['log' => $output];
    }

    /**
     * BUG-5 FIX: POST /api/xray/service/xraylog
     * Возвращает последние 200 строк /var/log/xray-core.log.
     * Ротация: /etc/newsyslog.conf.d/xray.conf (BUG-11 fix).
     * POST-only по той же причине, что logAction (BUG-5).
     */
    public function xraylogAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = $backend->configdRun('xray xraylog');
        return ['log' => $output];
    }

    /**
     * E5: POST /api/xray/service/validate
     * Сухой прогон конфига без перезапуска сервисов.
     * Генерирует config.json из текущего config.xml и запускает `xray -test`.
     * Не останавливает и не запускает демонов — безопасная операция.
     */
    public function validateAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = trim($backend->configdRun('xray validate'));
        $ok      = stripos($output, 'OK') !== false
                && stripos($output, 'ERROR') === false;
        return [
            'result'  => $ok ? 'ok' : 'failed',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * E4: GET /api/xray/service/diagnostics
     * Статистика TUN-интерфейса и uptime процессов для панели Diagnostics.
     * Данные читает xray-ifstats.php через configd.
     * GET: читаем данные — не модифицируем состояние, GET достаточно.
     */
    public function diagnosticsAction()
    {
        $backend = new Backend();
        $output  = trim($backend->configdRun('xray ifstats'));
        if (empty($output)) {
            return ['error' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON from ifstats: ' . $output];
        }
        return $data;
    }

    /**
     * I8: POST /api/xray/service/testconnect
     * Тестирует соединение через SOCKS5 прокси xray-core.
     * curl --socks5 127.0.0.1:10808 https://1.1.1.1 → http_code 200 = OK.
     */
    public function testconnectAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $backend = new Backend();
        $output  = trim($backend->configdRun('xray testconnect'));

        // curl выводит http_code (200, 000 при ошибке сети, etc.)
        $httpCode = (int)$output;
        if ($httpCode >= 200 && $httpCode < 400) {
            return [
                'result'    => 'ok',
                'http_code' => $httpCode,
                'message'   => "OK ({$httpCode}) — connection works",
            ];
        }

        return [
            'result'    => 'failed',
            'http_code' => $httpCode,
            'message'   => $httpCode === 0
                ? 'Could not connect — xray-core may be stopped or port unreachable'
                : "HTTP {$httpCode} — unexpected response from 1.1.1.1",
        ];
    }
}
