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
     * Decode JSON from configd script_output (may have warnings before/after the object).
     */
    private function decodeConfigdJson(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($output, '{');
        $end   = strrpos($output, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
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

    /**
     * POST /api/xray/service/bgpwrite — rewrite BIRD includes and birdc configure only.
     */
    public function bgpwriteAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = trim((new Backend())->configdRun('xray bgpwrite'));
        $failed = $output === ''
            || stripos($output, 'ERROR') !== false
            || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output !== '' ? $output : 'No response from configd',
        ];
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
        $result  = (string)$backend->configdRun('xray statusall');
        $decoded = $this->decodeConfigdJson($result);
        if (is_array($decoded)) {
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
     * POST-only — Auto route / Test auto route log.
     */
    public function autoroutelogAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = (new Backend())->configdRun('xray autoroutelog');
        return ['log' => $output];
    }

    /**
     * BUG-5 FIX: POST-only.
     */
    public function xraylogAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $output  = $backend->configdRun('xray xraylog' . ($uuid !== '' ? ' ' . $uuid : ''));
        return ['log' => $output];
    }

    public function birdlogAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = (new Backend())->configdRun('xray birdlog');
        return ['log' => $output];
    }

    public function dnstaplogAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = (new Backend())->configdRun('xray dnstaplog');
        return ['log' => $output];
    }

    public function birdloglevelAction()
    {
        $backend = new Backend();
        if ($this->request->isPost()) {
            $level = strtolower(trim((string)$this->request->getPost('level', 'string', '')));
            if ($level === '') {
                $json = $this->request->getJsonRawBody();
                if (is_object($json) && isset($json->level)) {
                    $level = strtolower(trim((string)$json->level));
                } elseif (is_array($json) && isset($json['level'])) {
                    $level = strtolower(trim((string)$json['level']));
                }
            }
            $allowed = ['debug', 'trace', 'info', 'remote', 'warning', 'error', 'auth', 'fatal', 'bug', 'all'];
            if (!in_array($level, $allowed, true)) {
                return ['result' => 'failed', 'message' => 'Invalid log class'];
            }
            $raw = trim((string)$backend->configdRun('xray birdloglevel ' . $level));
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return ['result' => 'failed', 'message' => $raw !== '' ? $raw : 'No response from configd'];
        }
        $raw = trim((string)$backend->configdRun('xray birdloglevel get'));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return ['result' => 'ok', 'level' => 'warning'];
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

    /**
     * POST /api/xray/service/birdactivetun — ручной one-shot Auto route
     * (только enabled + живые процессы; без cron-интервала).
     * Returns immediately; poll birdactivetunstatus until finished.
     */
    public function birdactivetunAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = trim((string)(new Backend())->configdRun('xray birdactivetun'));
        // Prefer explicit RESULT:* trailer. Do not treat birdc "Error:" lines as hard fail —
        // that caused Test All to show "Auto route failed" after a successful tun selection.
        $failed = $output === '';
        if (preg_match('/^RESULT:OK\s*$/m', $output)) {
            $failed = false;
        } elseif (preg_match('/^RESULT:ERROR\s*$/m', $output)) {
            $failed = true;
        } elseif (stripos($output, 'active tun selected:') !== false
            || stripos($output, 'active tun: plugin disabled') !== false
            || stripos($output, 'auto route:') !== false) {
            $failed = false;
        }
        $token = '';
        $statusRaw = trim((string)(new Backend())->configdRun('xray birdactivetunstatus'));
        $status = json_decode($statusRaw, true);
        if (is_array($status) && !empty($status['token'])) {
            $token = (string)$status['token'];
        }
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $failed
                ? ($output !== '' ? $output : 'No response from configd')
                : 'Auto route running...',
            'token'   => $token,
            'async'   => !$failed,
        ];
    }

    /**
     * GET /api/xray/service/birdactivetunstatus — async Test auto route progress.
     */
    public function birdactivetunstatusAction()
    {
        $raw = trim((string)(new Backend())->configdRun('xray birdactivetunstatus'));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'running' => false,
            'phase'   => 'idle',
            'result'  => '',
            'message' => $raw !== '' ? $raw : '',
        ];
    }

    /**
     * GET /api/xray/service/dnstapconf — current dnstap-bgp files.
     */
    public function dnstapconfAction()
    {
        $raw = trim((string)(new Backend())->configdRun('xray dnstapconf'));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return ['result' => 'failed', 'message' => $raw !== '' ? $raw : 'No response from configd'];
    }

    /**
     * POST /api/xray/service/dnstapwrite — stage files and write via configd.
     */
    public function dnstapwriteAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $conf        = $this->request->getPost('conf');
        $proxy       = $this->request->getPost('proxy');
        $proxyUrls   = $this->request->getPost('proxy_urls');
        $direct      = $this->request->getPost('direct');
        $directUrls  = $this->request->getPost('direct_urls');
        $block       = $this->request->getPost('block');
        $blockUrls   = $this->request->getPost('block_urls');
        $geodatUrls  = $this->request->getPost('geodat_urls');
        $blocked     = $this->request->getPost('blocked');
        $blockedUrls = $this->request->getPost('blocked_urls');
        $unblocked   = $this->request->getPost('unblocked');
        $unblockedUrls = $this->request->getPost('unblocked_urls');
        $domains     = $this->request->getPost('domains');
        $urls        = $this->request->getPost('urls');
        $rc          = $this->request->getPost('rc');
        if ($conf === null && $proxy === null && $proxyUrls === null
            && $direct === null && $directUrls === null
            && $block === null && $blockUrls === null && $geodatUrls === null
            && $blocked === null && $blockedUrls === null
            && $unblocked === null && $unblockedUrls === null
            && $domains === null && $urls === null && $rc === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $conf          = $json->conf ?? null;
                $proxy         = $json->proxy ?? null;
                $proxyUrls     = $json->proxy_urls ?? null;
                $direct        = $json->direct ?? null;
                $directUrls    = $json->direct_urls ?? null;
                $block         = $json->block ?? null;
                $blockUrls     = $json->block_urls ?? null;
                $geodatUrls    = $json->geodat_urls ?? null;
                $blocked       = $json->blocked ?? null;
                $blockedUrls   = $json->blocked_urls ?? null;
                $unblocked     = $json->unblocked ?? null;
                $unblockedUrls = $json->unblocked_urls ?? null;
                $domains       = $json->domains ?? null;
                $urls          = $json->urls ?? null;
                $rc            = $json->rc ?? null;
            } elseif (is_array($json)) {
                $conf          = $json['conf'] ?? null;
                $proxy         = $json['proxy'] ?? null;
                $proxyUrls     = $json['proxy_urls'] ?? null;
                $direct        = $json['direct'] ?? null;
                $directUrls    = $json['direct_urls'] ?? null;
                $block         = $json['block'] ?? null;
                $blockUrls     = $json['block_urls'] ?? null;
                $geodatUrls    = $json['geodat_urls'] ?? null;
                $blocked       = $json['blocked'] ?? null;
                $blockedUrls   = $json['blocked_urls'] ?? null;
                $unblocked     = $json['unblocked'] ?? null;
                $unblockedUrls = $json['unblocked_urls'] ?? null;
                $domains       = $json['domains'] ?? null;
                $urls          = $json['urls'] ?? null;
                $rc            = $json['rc'] ?? null;
            }
        }

        if ($conf === null && $proxy === null && $proxyUrls === null
            && $direct === null && $directUrls === null
            && $block === null && $blockUrls === null && $geodatUrls === null
            && $blocked === null && $blockedUrls === null
            && $unblocked === null && $unblockedUrls === null
            && $domains === null && $urls === null && $rc === null) {
            return ['result' => 'ok', 'message' => 'Nothing to write'];
        }

        $decodeRows = static function ($raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $j = json_decode($raw, true);
                return is_array($j) ? $j : [];
            }
            return [];
        };

        $staged = '/tmp/xray_dnstap_write.json';
        $payload = json_encode([
            'conf'        => $decodeRows($conf),
            'proxy'       => $decodeRows($proxy !== null ? $proxy : ($blocked !== null ? $blocked : $domains)),
            'proxy_urls'  => $decodeRows($proxyUrls !== null ? $proxyUrls : ($blockedUrls !== null ? $blockedUrls : $urls)),
            'direct'      => $decodeRows($direct !== null ? $direct : $unblocked),
            'direct_urls' => $decodeRows($directUrls !== null ? $directUrls : $unblockedUrls),
            'block'       => $decodeRows($block),
            'block_urls'  => $decodeRows($blockUrls),
            'geodat_urls' => $decodeRows($geodatUrls),
            'rc'          => $decodeRows($rc),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || file_put_contents($staged, $payload) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage dnstap files'];
        }
        @chmod($staged, 0600);

        $output = trim((string)(new Backend())->configdRun('xray dnstapwrite'));
        $failed = $output === ''
            || stripos($output, 'ERROR') !== false
            || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output !== '' ? $output : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/dnstapgeodat — download geosite.dat and list/save categories.
     */
    public function dnstapgeodatAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $geodatUrls = $this->request->getPost('geodat_urls');
        $geodatUrl  = $this->request->getPost('geodat_url');
        $geodatCats = $this->request->getPost('geodat_categories');
        if ($geodatUrls === null && $geodatCats === null && $geodatUrl === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $geodatUrls = $json->geodat_urls ?? null;
                $geodatUrl  = $json->geodat_url ?? null;
                $geodatCats = $json->geodat_categories ?? null;
            } elseif (is_array($json)) {
                $geodatUrls = $json['geodat_urls'] ?? null;
                $geodatUrl  = $json['geodat_url'] ?? null;
                $geodatCats = $json['geodat_categories'] ?? null;
            }
        }

        $decodeRows = static function ($raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $j = json_decode($raw, true);
                return is_array($j) ? $j : [];
            }
            return [];
        };

        $payload = [
            'geodat_urls' => $decodeRows($geodatUrls),
            'geodat_url'  => is_string($geodatUrl) ? $geodatUrl : (string)$geodatUrl,
        ];
        if ($geodatCats !== null) {
            $payload['geodat_categories'] = $decodeRows($geodatCats);
        }
        $staged = '/tmp/xray_dnstap_geodat.json';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($staged, $json) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage geosite request'];
        }
        @chmod($staged, 0600);

        $output = trim((string)(new Backend())->configdRun('xray dnstapgeodat'));
        $decoded = $this->decodeConfigdJson($output);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'result'  => 'failed',
            'message' => $output !== '' ? mb_substr($output, 0, 500) : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/dnstapfetchone — ✔ download one DNStap URL into cache and save it.
     */
    public function dnstapfetchoneAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $kind = $this->request->getPost('kind');
        $url = $this->request->getPost('url');
        $enabled = $this->request->getPost('enabled');
        if ($kind === null && $url === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $kind = $json->kind ?? null;
                $url = $json->url ?? null;
                $enabled = $json->enabled ?? null;
            } elseif (is_array($json)) {
                $kind = $json['kind'] ?? null;
                $url = $json['url'] ?? null;
                $enabled = $json['enabled'] ?? null;
            }
        }

        $staged = '/tmp/xray_dnstap_fetchone.json';
        $payload = json_encode([
            'kind'    => is_string($kind) ? $kind : (string)$kind,
            'url'     => is_string($url) ? $url : (string)$url,
            'enabled' => $enabled,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || file_put_contents($staged, $payload) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage dnstap fetchone'];
        }
        @chmod($staged, 0600);

        $raw = trim((string)(new Backend())->configdRun('xray dnstapfetchone'));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'result'  => (stripos($raw, 'failed') !== false || stripos($raw, 'ERROR') !== false) ? 'failed' : 'ok',
            'message' => $raw !== '' ? $raw : 'No response from configd',
        ];
    }

    /**
     * GET /api/xray/service/staticconf — Static IP list URLs and prefix counts.
     */
    public function staticconfAction()
    {
        $raw = trim((string)(new Backend())->configdRun('xray staticconf'));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return ['result' => 'failed', 'message' => $raw !== '' ? $raw : 'No response from configd'];
    }

    /**
     * POST /api/xray/service/staticwrite — download IP lists and write BIRD static includes.
     */
    public function staticwriteAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $proxyUrls  = $this->request->getPost('proxy_urls');
        $directUrls = $this->request->getPost('direct_urls');
        $blockUrls  = $this->request->getPost('block_urls');
        $geodatUrls = $this->request->getPost('geodat_urls');
        $enabled    = $this->request->getPost('enabled');
        if ($proxyUrls === null && $directUrls === null && $blockUrls === null && $geodatUrls === null && $enabled === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $proxyUrls  = $json->proxy_urls ?? null;
                $directUrls = $json->direct_urls ?? null;
                $blockUrls  = $json->block_urls ?? null;
                $geodatUrls = $json->geodat_urls ?? null;
                $enabled    = $json->enabled ?? null;
            } elseif (is_array($json)) {
                $proxyUrls  = $json['proxy_urls'] ?? null;
                $directUrls = $json['direct_urls'] ?? null;
                $blockUrls  = $json['block_urls'] ?? null;
                $geodatUrls = $json['geodat_urls'] ?? null;
                $enabled    = $json['enabled'] ?? null;
            }
        }

        // SimpleActionButton may call this endpoint again after onPreAction with an empty body.
        if ($proxyUrls === null && $directUrls === null && $blockUrls === null && $geodatUrls === null && $enabled === null) {
            return ['result' => 'ok', 'message' => 'Nothing to write'];
        }

        $decodeRows = static function ($raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $j = json_decode($raw, true);
                return is_array($j) ? $j : [];
            }
            return [];
        };

        $staged = '/tmp/xray_static_write.json';
        $payload = json_encode([
            'enabled'     => $enabled,
            'proxy_urls'  => $decodeRows($proxyUrls),
            'direct_urls' => $decodeRows($directUrls),
            'block_urls'  => $decodeRows($blockUrls),
            'geodat_urls' => $decodeRows($geodatUrls),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || file_put_contents($staged, $payload) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage static write'];
        }
        @chmod($staged, 0600);

        $output = trim((string)(new Backend())->configdRun('xray staticwrite'));
        $failed = $output === ''
            || stripos($output, 'ERROR') !== false
            || (stripos($output, 'failed') !== false && stripos($output, 'OK:') === false);
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output !== '' ? $output : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/staticextra — add/remove one Extra IP and rebuild static routes.
     */
    public function staticextraAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $action = $this->request->getPost('action');
        $kind   = $this->request->getPost('kind');
        $prefix = $this->request->getPost('prefix');
        if ($action === null && $kind === null && $prefix === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $action = $json->action ?? null;
                $kind   = $json->kind ?? null;
                $prefix = $json->prefix ?? null;
            } elseif (is_array($json)) {
                $action = $json['action'] ?? null;
                $kind   = $json['kind'] ?? null;
                $prefix = $json['prefix'] ?? null;
            }
        }

        $staged = '/tmp/xray_static_extra.json';
        $payload = json_encode([
            'action' => is_string($action) ? $action : (string)$action,
            'kind'   => is_string($kind) ? $kind : (string)$kind,
            'prefix' => is_string($prefix) ? $prefix : (string)$prefix,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || file_put_contents($staged, $payload) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage extra prefix'];
        }
        @chmod($staged, 0600);

        $raw = trim((string)(new Backend())->configdRun('xray staticextra'));
        $decoded = $this->decodeConfigdJson($raw);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'result'  => (stripos($raw, 'failed') !== false || stripos($raw, 'ERROR') !== false) ? 'failed' : 'ok',
            'message' => $raw !== '' ? $raw : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/staticextrawrite — replace Extra IP lists and rebuild static routes.
     */
    public function staticextrawriteAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $proxy  = $this->request->getPost('proxy');
        $direct = $this->request->getPost('direct');
        $block  = $this->request->getPost('block');
        if ($proxy === null && $direct === null && $block === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $proxy  = $json->proxy ?? null;
                $direct = $json->direct ?? null;
                $block  = $json->block ?? null;
            } elseif (is_array($json)) {
                $proxy  = $json['proxy'] ?? null;
                $direct = $json['direct'] ?? null;
                $block  = $json['block'] ?? null;
            }
        }
        if ($proxy === null && $direct === null && $block === null) {
            return ['result' => 'ok', 'message' => 'Nothing to write'];
        }

        $staged = '/tmp/xray_static_extra.json';
        $payload = json_encode([
            'proxy'  => $proxy,
            'direct' => $direct,
            'block'  => $block,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || file_put_contents($staged, $payload) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage extra prefix lists'];
        }
        @chmod($staged, 0600);

        $raw = trim((string)(new Backend())->configdRun('xray staticextrawrite'));
        $decoded = $this->decodeConfigdJson($raw);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'result'  => (stripos($raw, 'failed') !== false || stripos($raw, 'ERROR') !== false) ? 'failed' : 'ok',
            'message' => $raw !== '' ? $raw : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/staticfetchone — ✔ download one Static URL into cache and save it.
     */
    public function staticfetchoneAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $kind = $this->request->getPost('kind');
        $url = $this->request->getPost('url');
        $enabled = $this->request->getPost('enabled');
        if ($kind === null && $url === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $kind = $json->kind ?? null;
                $url = $json->url ?? null;
                $enabled = $json->enabled ?? null;
            } elseif (is_array($json)) {
                $kind = $json['kind'] ?? null;
                $url = $json['url'] ?? null;
                $enabled = $json['enabled'] ?? null;
            }
        }

        $staged = '/tmp/xray_static_fetchone.json';
        $payload = json_encode([
            'kind'    => is_string($kind) ? $kind : (string)$kind,
            'url'     => is_string($url) ? $url : (string)$url,
            'enabled' => $enabled,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || file_put_contents($staged, $payload) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage static fetchone'];
        }
        @chmod($staged, 0600);

        $raw = trim((string)(new Backend())->configdRun('xray staticfetchone'));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'result'  => (stripos($raw, 'failed') !== false || stripos($raw, 'ERROR') !== false) ? 'failed' : 'ok',
            'message' => $raw !== '' ? $raw : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/staticgeodat — download geoip.dat and list/save categories.
     */
    public function staticgeodatAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $geodatUrls = $this->request->getPost('geodat_urls');
        $geodatUrl  = $this->request->getPost('geodat_url');
        $geodatCats = $this->request->getPost('geodat_categories');
        if ($geodatUrls === null && $geodatCats === null && $geodatUrl === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $geodatUrls = $json->geodat_urls ?? null;
                $geodatUrl  = $json->geodat_url ?? null;
                $geodatCats = $json->geodat_categories ?? null;
            } elseif (is_array($json)) {
                $geodatUrls = $json['geodat_urls'] ?? null;
                $geodatUrl  = $json['geodat_url'] ?? null;
                $geodatCats = $json['geodat_categories'] ?? null;
            }
        }

        $decodeRows = static function ($raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $j = json_decode($raw, true);
                return is_array($j) ? $j : [];
            }
            return [];
        };

        $payload = [
            'geodat_urls' => $decodeRows($geodatUrls),
            'geodat_url'  => is_string($geodatUrl) ? $geodatUrl : (string)$geodatUrl,
        ];
        if ($geodatCats !== null) {
            $payload['geodat_categories'] = $decodeRows($geodatCats);
        }
        $staged = '/tmp/xray_static_geodat.json';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($staged, $json) === false) {
            return ['result' => 'failed', 'message' => 'Cannot stage static geodat request'];
        }
        @chmod($staged, 0600);

        $output = trim((string)(new Backend())->configdRun('xray staticgeodat'));
        $decoded = $this->decodeConfigdJson($output);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'result'  => 'failed',
            'message' => $output !== '' ? mb_substr($output, 0, 500) : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/dnstapstart — Unbound include + start dnstap-bgp.
     */
    public function dnstapstartAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = trim((string)(new Backend())->configdRun('xray dnstapstart'));
        $failed = $output === ''
            || stripos($output, 'ERROR') !== false
            || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output !== '' ? $output : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/dnstapstop — stop dnstap-bgp and remove Unbound include.
     */
    public function dnstapstopAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = trim((string)(new Backend())->configdRun('xray dnstapstop'));
        $failed = $output === ''
            || stripos($output, 'ERROR') !== false
            || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output !== '' ? $output : 'No response from configd',
        ];
    }

    /**
     * POST /api/xray/service/domainroute — domain or IP → birdcl show route for <ip> all.
     */
    public function domainrouteAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required', 'rows' => []];
        }
        $domain = $this->request->getPost('domain');
        if ($domain === null) {
            $json = $this->request->getJsonRawBody();
            if (is_object($json)) {
                $domain = $json->domain ?? '';
            } elseif (is_array($json)) {
                $domain = $json['domain'] ?? '';
            }
        }
        $domain = trim((string)$domain);
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?/', $domain, $m)) {
            $domain = $m[1];
        } else {
            $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        }
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || !preg_match('/^[A-Za-z0-9._:\-]+$/', $domain)) {
            return ['result' => 'failed', 'message' => 'Invalid domain or IP', 'rows' => []];
        }
        $output = trim((string)(new Backend())->configdRun('xray domainroute ' . $domain));
        $decoded = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        return [
            'result'  => 'failed',
            'message' => $output !== '' ? $output : 'No response from configd',
            'rows'    => [],
        ];
    }

    /**
     * GET/POST /api/xray/service/tunassignments
     * Unassigned instance TUN devices (not in Interfaces → Assignments).
     */
    public function tunassignmentsAction()
    {
        $script = '/usr/local/opnsense/scripts/Xray/xray-gateways.php';
        if (is_readable($script)) {
            require_once $script;
        }
        $missing = function_exists('xray_gateways_unassigned_instances')
            ? xray_gateways_unassigned_instances()
            : [];
        return [
            'result'  => 'ok',
            'missing' => $missing,
            'ok'      => $missing === [],
        ];
    }
}
