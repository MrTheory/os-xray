<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * Handles enable/disable flag.
 * Routes: GET/POST /api/xray/general/get|set
 * mapDataToFormUI key: 'frm_general_settings' → '/api/xray/general/get'
 */
class GeneralController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\General';
    protected static $internalModelName  = 'general';
}
