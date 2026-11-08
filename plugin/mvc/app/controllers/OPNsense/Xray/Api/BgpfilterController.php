<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class BgpfilterController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\BgpFilter';
    protected static $internalModelName  = 'filter';

    public function searchItemAction()
    {
        (new \OPNsense\Xray\BgpCommunity())->seedDefaultCommunitiesIfEmpty();
        (new \OPNsense\Xray\BgpCommunity())->migrateCommunityFileNames();
        (new \OPNsense\Xray\BgpFilter())->seedDefaultFiltersIfEmpty();
        (new \OPNsense\Xray\BgpFilter())->migrateAcceptFilterNames();
        $response = $this->searchBase('filter', [
            'enabled',
            'name',
            'community',
            'family',
            'tun_if',
            'blocks',
        ]);
        if (!empty($response['rows'])) {
            $names = [];
            $comms = new \OPNsense\Xray\BgpCommunity();
            if (method_exists($comms->community, 'iterateItems')) {
                foreach ($comms->community->iterateItems() as $uuid => $item) {
                    $names[$uuid] = (string)$item->name;
                }
            }
            foreach ($response['rows'] as &$row) {
                $shown = [];
                foreach (\OPNsense\Xray\BgpFilter::parseBlocks($row) as $b) {
                    $c = (string)($b['community'] ?? '');
                    if ($c === '') {
                        continue;
                    }
                    $shown[] = $names[$c] ?? $c;
                }
                $row['community'] = $shown !== [] ? implode(', ', $shown) : '';
            }
            unset($row);
        }
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        $result = $this->toggleBase('filter', $uuid, $enabled);
        $this->syncBird();
        return $result;
    }

    public function getItemAction($uuid = null)
    {
        try {
            (new \OPNsense\Xray\BgpCommunity())->seedDefaultCommunitiesIfEmpty();
            (new \OPNsense\Xray\BgpCommunity())->ensureDnstapBlockedCommunity();
            (new \OPNsense\Xray\BgpCommunity())->ensureDnstapDirectCommunity();
            (new \OPNsense\Xray\BgpCommunity())->ensureDnstapBlockCommunity();
            (new \OPNsense\Xray\BgpFilter())->migrateAcceptFilterNames();
        } catch (\Throwable $e) {
            syslog(LOG_ERR, 'os-xray BGP seed: ' . $e->getMessage());
        }
        $result = $this->getBase('filter', 'filter', $uuid);
        if (isset($result['filter']) && is_array($result['filter']) && array_key_exists('community', $result['filter'])) {
            $result['filter']['community'] = \OPNsense\Xray\BgpCommunity::overlayDropdown(
                $result['filter']['community']
            );
        }
        return $result;
    }

    public function addItemAction()
    {
        $result = $this->addBase('filter', 'filter');
        $this->syncBird();
        return $result;
    }

    public function setItemAction($uuid)
    {
        $result = $this->setBase('filter', 'filter', $uuid);
        $this->syncBird();
        return $result;
    }

    public function delItemAction($uuid)
    {
        $result = $this->delBase('filter', $uuid);
        $this->syncBird();
        return $result;
    }

    private function syncBird(): void
    {
        (new Backend())->configdRun('xray bgpwrite');
    }
}
