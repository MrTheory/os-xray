<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';

    public function searchItemAction()
    {
        $response = $this->searchBase('instance', ['enabled', 'name', 'outbound_config']);
        if (!empty($response['rows'])) {
            foreach ($response['rows'] as &$row) {
                $ob    = json_decode($row['outbound_config'] ?? '', true);
                $vnext = $ob['settings']['vnext'][0] ?? [];
                $row['server_address'] = $vnext['address'] ?? '';
                $row['server_port']    = isset($vnext['port']) ? (string)$vnext['port'] : '';
                unset($row['outbound_config']);
            }
            unset($row);
        }
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('instance', 'instance', $uuid);
    }

    public function addItemAction()
    {
        $result = $this->addBase('instance', 'instance');
        if (($result['result'] ?? '') !== 'failed') {
            $this->syncWanRoutes();
        }
        return $result;
    }

    public function setItemAction($uuid)
    {
        $result = $this->setBase('instance', 'instance', $uuid);
        if (($result['result'] ?? '') !== 'failed') {
            $this->syncWanRoutes();
        }
        return $result;
    }

    public function delItemAction($uuid)
    {
        $uuid = preg_replace('/[^0-9a-fA-F\-]/', '', (string)$uuid);
        // Config first: waiting on configd/BIRD caused HTTP 504 and delBase never ran,
        // so the row stayed in the grid.
        $result = $this->delBase('instance', $uuid);
        if (($result['result'] ?? '') === 'deleted' && strlen($uuid) >= 36) {
            $php    = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
            $script = '/usr/local/opnsense/scripts/Xray/xray-service-control.php';
            if (is_file($script)) {
                exec(
                    escapeshellarg($php) . ' ' . escapeshellarg($script)
                    . ' delete ' . escapeshellarg($uuid) . ' >/dev/null 2>&1 &'
                );
            }
            $this->syncWanRoutes();
        }
        return $result;
    }

    private function syncWanRoutes(): void
    {
        $script = '/usr/local/opnsense/scripts/Xray/xray-wan-static-routes.php';
        if (!is_readable($script)) {
            return;
        }
        require_once $script;
        if (!function_exists('xray_wan_routes_sync_all')) {
            return;
        }
        ob_start();
        try {
            xray_wan_routes_sync_all(true);
        } catch (\Throwable $e) {
            syslog(LOG_ERR, 'os-xray wan routes: ' . $e->getMessage());
        }
        $buf = trim((string)ob_get_clean());
        if ($buf !== '') {
            syslog(LOG_INFO, 'os-xray wan routes: ' . $buf);
        }
    }
}
