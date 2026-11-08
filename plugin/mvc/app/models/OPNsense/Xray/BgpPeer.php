<?php

namespace OPNsense\Xray;

use OPNsense\Base\BaseModel;
use OPNsense\Core\Config;

class BgpPeer extends BaseModel
{
    public static function builtinPeerRows(): array
    {
        $networkComm = '65444:120, 65444:200, 65444:210, 65444:700, 65444:710, 65444:720, 65444:730, 65444:740, 65444:750, 65444:760, 65444:770, 65444:780, 65444:790, 65444:800';
        return [
            [
                'enabled'              => '0',
                'name'                 => 'refilter',
                'local_as'             => '65103',
                'neighbor'             => '165.22.127.207',
                'neighbor_as'          => '65412',
                'source_address'       => '',
                'ipv4'                 => '1',
                'ipv4_import'          => 'filter_refilter',
                'ipv4_community_name'  => '',
                'ipv4_community'       => '',
                'ipv6'                 => '0',
                'ipv6_import'          => '',
                'ipv6_community_name'  => '',
                'ipv6_community'       => '',
                'hold_time'            => '240',
            ],
            [
                'enabled'              => '0',
                'name'                 => 'antifilter_download',
                'local_as'             => '65103',
                'neighbor'             => '45.154.73.71',
                'neighbor_as'          => '65432',
                'source_address'       => '',
                'ipv4'                 => '1',
                'ipv4_import'          => 'filter_antifilter_download',
                'ipv4_community_name'  => 'community_ANTIFILTER_DOWNLOAD',
                'ipv4_community'       => '65432:500',
                'ipv6'                 => '0',
                'ipv6_import'          => '',
                'ipv6_community_name'  => '',
                'ipv6_community'       => '',
                'hold_time'            => '240',
            ],
            [
                'enabled'              => '0',
                'name'                 => 'antifilter_network',
                'local_as'             => '65103',
                'neighbor'             => '45.148.244.55',
                'neighbor_as'          => '65444',
                'source_address'       => '',
                'ipv4'                 => '1',
                'ipv4_import'          => 'filter_antifilter_network_v4',
                'ipv4_community_name'  => 'community_ANTIFILTER_NETWORK',
                'ipv4_community'       => $networkComm,
                'ipv6'                 => '1',
                'ipv6_import'          => 'filter_antifilter_network_v6',
                'ipv6_community_name'  => 'community_ANTIFILTER_NETWORK',
                'ipv6_community'       => $networkComm,
                'hold_time'            => '240',
            ],
        ];
    }

    public function seedDefaultPeersIfEmpty(): void
    {
        if (method_exists($this->peer, 'iterateItems')) {
            foreach ($this->peer->iterateItems() as $item) {
                return;
            }
        }

        if (!method_exists($this->peer, 'add')) {
            return;
        }

        foreach (self::builtinPeerRows() as $data) {
            $script = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
            if (is_readable($script)) {
                require_once $script;
                if (function_exists('xray_source_for_peer')) {
                    $data['source_address'] = xray_source_for_peer($data);
                }
            }
            $uuid = $this->peer->add();
            $node = $this->peer->{$uuid};
            if ($node !== null && method_exists($node, 'setNodes')) {
                $node->setNodes($data);
            }
        }

        $this->persistConfig();
    }

    public function ensureDnstapPeerFilters(): void
    {
        $filters = new BgpFilter();
        $filters->ensureDnstapFilters();
        $v4 = $filters->filterUuidByName('filter_dnstap_v4');
        $v6 = $filters->filterUuidByName('filter_dnstap_v6');
        if ($v4 === '' && $v6 === '') {
            return;
        }
        if (!method_exists($this->peer, 'iterateItems')) {
            return;
        }
        $changed = false;
        foreach ($this->peer->iterateItems() as $item) {
            if (strcasecmp(trim((string)$item->name), 'dnstap') !== 0) {
                continue;
            }
            $imp4 = trim((string)$item->ipv4_import);
            $imp6 = trim((string)$item->ipv6_import);
            if ($v4 !== '' && ($imp4 === '' || $imp4 === 'none' || strcasecmp($imp4, 'filter_dnstap_v4') === 0)) {
                $item->ipv4_import = $v4;
                $changed = true;
            }
            if ($v6 !== '' && ($imp6 === '' || $imp6 === 'none' || strcasecmp($imp6, 'filter_dnstap_v6') === 0)) {
                $item->ipv6_import = $v6;
                $changed = true;
            }
            if (trim((string)$item->ipv4_community_name) === '') {
                $item->ipv4_community_name = 'community_DNSTAP_PROXY';
                $changed = true;
            }
            if (trim((string)$item->ipv6_community_name) === '') {
                $item->ipv6_community_name = 'community_DNSTAP_PROXY';
                $changed = true;
            }
            break;
        }
        if ($changed) {
            $this->persistConfig();
        }
    }

    public function migrateAcceptImportNames(): void
    {
        if (!method_exists($this->peer, 'iterateItems')) {
            return;
        }
        $byName = [];
        $uuids  = [];
        $filters = new BgpFilter();
        if (method_exists($filters->filter, 'iterateItems')) {
            foreach ($filters->filter->iterateItems() as $uuid => $item) {
                $n = (string)$item->name;
                $byName[$n] = $uuid;
                $uuids[$uuid] = true;
                if (strncasecmp($n, 'filter_', 7) === 0) {
                    $short = substr($n, 7);
                    $byName[$short] = $uuid;
                    $byName['accept_' . $short] = $uuid;
                }
            }
        }
        $changed = false;
        foreach ($this->peer->iterateItems() as $item) {
            foreach (['ipv4_import', 'ipv6_import'] as $field) {
                $n = trim((string)$item->{$field});
                if ($n === '' || isset($uuids[$n])) {
                    continue;
                }
                if (isset($byName[$n])) {
                    $item->{$field} = $byName[$n];
                    $changed = true;
                }
            }
            foreach (['ipv4_community_name', 'ipv6_community_name'] as $field) {
                $n = trim((string)$item->{$field});
                if ($n === '' || strncasecmp($n, 'community_', 10) === 0) {
                    continue;
                }
                $item->{$field} = 'community_' . $n;
                $changed = true;
            }
        }
        if ($changed) {
            $this->persistConfig();
        }
    }

    private function persistConfig(): void
    {
        $this->serializeToConfig(false, true);
        Config::getInstance()->save();
        if (method_exists(self::class, 'flushCacheData')) {
            self::flushCacheData();
        }
        if (method_exists(BgpFilter::class, 'flushCacheData')) {
            BgpFilter::flushCacheData();
        }
    }
}
