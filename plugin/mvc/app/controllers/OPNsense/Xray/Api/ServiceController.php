<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Service control: start / stop / reconfigure / status / log / testconnect.
 *
 * v3.0.0: все service-actions принимают опциональный $uuid инстанса.
 * Если $uuid пустой — действие применяется ко всем инстансам.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\OPNsense\Xray\General';
    protected static $internalServiceTemplate = 'OPNsense/Xray';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'xray';

    /**
     * Санитизирует UUID аргумент из URL: оставляет только hex-символы и дефисы.
     */
    private function sanitizeUuid(string $uuid): string
    {
        return preg_replace('/[^0-9a-fA-F\-]/', '', $uuid);
    }

    /**
     * BUG-12 FIX: возвращает реальный статус reconfigure.
     */
    public function reconfigureAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray reconfigure' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

        if (empty($output)) {
            return ['result' => 'failed', 'message' => 'No response from configd (timeout or service unavailable)'];
        }

        $hasError   = stripos($output, 'ERROR')   !== false
                   || stripos($output, 'failed')  !== false;
        $hasSuccess = stripos($output, 'OK')       !== false
                   || stripos($output, 'disabled') !== false;

        if ($hasError || !$hasSuccess) {
            return ['result' => 'failed', 'message' => $output];
        }

        return ['result' => 'ok', 'message' => $output];
    }

    public function statusAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray status' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $result  = $backend->configdRun($cmd);
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => trim($result)];
    }

    /**
     * GET /api/xray/service/statusAll — статус всех инстансов.
     */
    public function statusAllAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray statusall');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['error' => trim($result)];
    }

    public function startAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray start' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function stopAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray stop' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        return [
            'result'  => empty($output) ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function restartAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray restart' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * BUG-5 FIX: POST-only — лог содержит чувствительные данные.
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
     * BUG-5 FIX: POST-only.
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
     * E5: POST /api/xray/service/validate[/{uuid}]
     */
    public function validateAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray validate' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $ok      = stripos($output, 'OK')    !== false
                && stripos($output, 'ERROR') === false;
        return [
            'result'  => $ok ? 'ok' : 'failed',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * GET /api/xray/service/version
     */
    public function versionAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray version');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['version' => 'unknown'];
    }

    /**
     * E4: GET /api/xray/service/diagnostics[/{uuid}]
     */
    public function diagnosticsAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray ifstats' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
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
     * I8: POST /api/xray/service/testconnect[/{uuid}]
     */
    public function testconnectAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray testconnect' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

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
