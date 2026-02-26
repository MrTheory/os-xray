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
     * B10: возвращает реальный статус выполнения reconfigure.
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $backend = new Backend();
        $output  = trim($backend->configdRun('xray reconfigure'));

        if (stripos($output, 'ERROR') !== false) {
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
     * I3: GET /api/xray/service/log
     * Возвращает последние 150 строк /tmp/xray_syshook.log через configd action [log].
     */
    public function logAction()
    {
        $backend = new Backend();
        $output  = $backend->configdRun('xray log');
        return ['log' => $output];
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
