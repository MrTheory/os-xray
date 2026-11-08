<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class BgppeerController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\BgpPeer';
    protected static $internalModelName  = 'peer';

    public function searchItemAction()
    {
        try {
            (new \OPNsense\Xray\BgpCommunity())->ensureDnstapBlockedCommunity();
            (new \OPNsense\Xray\BgpCommunity())->ensureDnstapDirectCommunity();
            (new \OPNsense\Xray\BgpCommunity())->ensureDnstapBlockCommunity();
            (new \OPNsense\Xray\BgpFilter())->seedDefaultFiltersIfEmpty();
            (new \OPNsense\Xray\BgpFilter())->migrateAcceptFilterNames();
            (new \OPNsense\Xray\BgpFilter())->ensureDnstapFilters();
            (new \OPNsense\Xray\BgpPeer())->seedDefaultPeersIfEmpty();
            (new \OPNsense\Xray\BgpPeer())->migrateAcceptImportNames();
            (new \OPNsense\Xray\BgpPeer())->ensureDnstapPeerFilters();
        } catch (\Throwable $e) {
            syslog(LOG_ERR, 'os-xray BGP seed: ' . $e->getMessage());
        }
        $response = $this->searchBase('peer', [
            'enabled',
            'name',
            'neighbor',
            'neighbor_as',
            'local_as',
            'ipv4',
            'ipv6',
        ]);
        $tun4 = '';
        $tun6 = '';
        $script = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
        if (is_readable($script)) {
            require_once $script;
            if (function_exists('xray_bird_active_tun_if')) {
                $tun4 = xray_bird_active_tun_if('ipv4');
                $tun6 = xray_bird_active_tun_if('ipv6');
            }
        }
        $byTun = $this->instanceNamesByTun();
        if (!empty($response['rows'])) {
            foreach ($response['rows'] as &$row) {
                $row['ipv4_route_int'] = $this->formatRouteInt($tun4, $byTun);
                $row['ipv6_route_int'] = $this->formatRouteInt($tun6, $byTun);
                $row['prefixes4'] = '';
                $row['prefixes6'] = '';
            }
            unset($row);
        }
        return $this->dnstapPeerFirst($response);
    }

    private function dnstapPeerFirst(array $response): array
    {
        $rows = $response['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }
        $dnstap = [];
        $other  = [];
        foreach ($rows as $row) {
            if (strcasecmp((string)($row['name'] ?? ''), 'dnstap') === 0) {
                $dnstap[] = $row;
            } else {
                $other[] = $row;
            }
        }
        if ($dnstap === []) {
            $mdl = new \OPNsense\Xray\BgpPeer();
            if (method_exists($mdl->peer, 'iterateItems')) {
                foreach ($mdl->peer->iterateItems() as $uuid => $item) {
                    if (strcasecmp(trim((string)$item->name), 'dnstap') !== 0) {
                        continue;
                    }
                    $dnstap[] = [
                        'uuid'        => (string)$uuid,
                        'enabled'     => (string)$item->enabled,
                        'name'        => (string)$item->name,
                        'neighbor'    => (string)$item->neighbor,
                        'neighbor_as' => (string)$item->neighbor_as,
                        'local_as'    => (string)$item->local_as,
                        'ipv4'        => (string)$item->ipv4,
                        'ipv6'        => (string)$item->ipv6,
                    ];
                    $tun4 = '';
                    $tun6 = '';
                    $script = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
                    if (is_readable($script)) {
                        require_once $script;
                        if (function_exists('xray_bird_active_tun_if')) {
                            $tun4 = xray_bird_active_tun_if('ipv4');
                            $tun6 = xray_bird_active_tun_if('ipv6');
                        }
                    }
                    $byTun = $this->instanceNamesByTun();
                    $dnstap[0]['ipv4_route_int'] = $this->formatRouteInt($tun4, $byTun);
                    $dnstap[0]['ipv6_route_int'] = $this->formatRouteInt($tun6, $byTun);
                    $dnstap[0]['prefixes4'] = '';
                    $dnstap[0]['prefixes6'] = '';
                    break;
                }
            }
        }
        $response['rows'] = array_merge($dnstap, $other);
        return $response;
    }

    private function instanceNamesByTun(): array
    {
        $map = [];
        $mdl = new \OPNsense\Xray\Instance();
        if (!method_exists($mdl->instance, 'iterateItems')) {
            return $map;
        }
        foreach ($mdl->instance->iterateItems() as $item) {
            if ((string)$item->enabled !== '1') {
                continue;
            }
            $tun = trim((string)$item->tun_interface);
            if ($tun === '') {
                continue;
            }
            $name = trim((string)$item->name);
            $map[$tun][] = $name !== '' ? $name : $tun;
        }
        return $map;
    }

    private function formatRouteInt(string $tun, array $byTun): string
    {
        if ($tun === '') {
            return '';
        }
        $names = $byTun[$tun] ?? [];
        if ($names === []) {
            return $tun;
        }
        return $tun . ' (' . implode(', ', $names) . ')';
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('peer', $uuid, $enabled);
    }

    public function startItemAction($uuid)
    {
        return $this->setPeerEnabledAndReload($uuid, '1');
    }

    public function stopItemAction($uuid)
    {
        return $this->setPeerEnabledAndReload($uuid, '0');
    }

    public function statusAllAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray bgpstatus');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        return ['error' => trim((string)$result), 'running' => false, 'peers' => []];
    }

    public function applyAction()
    {
        return $this->birdCmd('bgpwrite');
    }

    public function getItemAction($uuid = null)
    {
        try {
            (new \OPNsense\Xray\BgpFilter())->ensureDnstapFilters();
            (new \OPNsense\Xray\BgpPeer())->migrateAcceptImportNames();
            (new \OPNsense\Xray\BgpPeer())->ensureDnstapPeerFilters();
        } catch (\Throwable $e) {
            syslog(LOG_ERR, 'os-xray BGP seed: ' . $e->getMessage());
        }
        $result = $this->getBase('peer', 'peer', $uuid);
        if (isset($result['peer']) && is_array($result['peer'])) {
            foreach (['ipv4_import', 'ipv6_import'] as $field) {
                if (array_key_exists($field, $result['peer'])) {
                    $result['peer'][$field] = \OPNsense\Xray\BgpFilter::overlayDropdown(
                        $result['peer'][$field]
                    );
                }
            }
        }
        return $result;
    }

    public function addItemAction()
    {
        $result = $this->addBase('peer', 'peer');
        if (($result['result'] ?? '') !== 'failed') {
            $this->syncBirdFiles();
            $this->syncWanRoutes();
        }
        return $result;
    }

    public function setItemAction($uuid)
    {
        $result = $this->setBase('peer', 'peer', $uuid);
        if (($result['result'] ?? '') !== 'failed') {
            $this->syncBirdFiles();
            $this->syncWanRoutes();
        }
        return $result;
    }

    public function delItemAction($uuid)
    {
        $result = $this->delBase('peer', $uuid);
        if (($result['result'] ?? '') !== 'failed') {
            $this->syncBirdFiles();
            $this->syncWanRoutes();
        }
        return $result;
    }

    private function syncBirdFiles(): void
    {
        (new Backend())->configdRun('xray bgpwrite');
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

    private function setPeerEnabledAndReload($uuid, string $enabled): array
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $result = $this->toggleBase('peer', $uuid, $enabled);
        if (($result['result'] ?? '') === 'failed') {
            return $result;
        }
        $bird = $this->birdCmd('bgprestart');
        if (($bird['result'] ?? '') === 'failed') {
            $result['result'] = 'failed';
            $result['message'] = $bird['message'] ?? 'Failed to rewrite includes and restart BIRD';
        }
        return $result;
    }

    private function birdCmd(string $action): array
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $output = trim((new Backend())->configdRun('xray ' . $action));
        $failed = $output === ''
            || stripos($output, 'ERROR') !== false
            || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output !== '' ? $output : 'No response from configd',
        ];
    }
}
