<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';

    public function searchItemAction()
    {
        return $this->searchBase(
            'instance',
            ['name', 'server_address', 'server_port', 'config_mode']
        );
    }

    public function getItemAction($uuid = null)
    {
        return $this->getBase('instance', 'instance', $uuid);
    }

    public function addItemAction()
    {
        return $this->addBase('instance', 'instance');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('instance', 'instance', $uuid);
    }

    public function delItemAction($uuid)
    {
        return $this->delBase('instance', $uuid);
    }
}
