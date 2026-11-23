<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class BgpcommunityController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\BgpCommunity';
    protected static $internalModelName  = 'community';

    public function searchItemAction()
    {
        (new \OPNsense\Xray\BgpCommunity())->seedDefaultCommunitiesIfEmpty();
        (new \OPNsense\Xray\BgpCommunity())->migrateCommunityFileNames();
        return $this->searchBase('community', [
            'enabled',
            'name',
            'communities',
        ]);
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        $result = $this->toggleBase('community', $uuid, $enabled);
        $this->syncBird();
        return $result;
    }

    public function getItemAction($uuid = null)
    {
        (new \OPNsense\Xray\BgpCommunity())->migrateCommunityFileNames();
        return $this->getBase('community', 'community', $uuid);
    }

    public function addItemAction()
    {
        $result = $this->addBase('community', 'community');
        $this->syncBird();
        return $result;
    }

    public function setItemAction($uuid)
    {
        $result = $this->setBase('community', 'community', $uuid);
        $this->syncBird();
        return $result;
    }

    public function delItemAction($uuid)
    {
        $result = $this->delBase('community', $uuid);
        $this->syncBird();
        return $result;
    }

    private function syncBird(): void
    {
        (new Backend())->configdRun('xray bgpwrite');
    }
}
