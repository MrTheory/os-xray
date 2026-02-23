<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * Handles connection settings (single flat model, not ArrayField).
 *
 * Do NOT override getAction/setAction — the base class handles it automatically:
 *   GET  /api/xray/instance/get  → returns {'instance': { all fields }}
 *   POST /api/xray/instance/set  → saves  {'instance': { all fields }}
 *
 * $internalModelName drives the JSON key in the response.
 */
class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';
}
