<?php

namespace OPNsense\Xray;

use OPNsense\Base\BaseModel;
use OPNsense\Core\Config;

class BgpCommunity extends BaseModel
{
    public static function builtinCommunityRows(): array
    {
        $networkComm = '65444:120, 65444:200, 65444:210, 65444:700, 65444:710, 65444:720, 65444:730, 65444:740, 65444:750, 65444:760, 65444:770, 65444:780, 65444:790, 65444:800';
        return [
            [
                'enabled'      => '1',
                'name'         => 'community_ANTIFILTER_DOWNLOAD',
                'communities'  => '65432:500',
            ],
            [
                'enabled'      => '1',
                'name'         => 'community_ANTIFILTER_NETWORK',
                'communities'  => $networkComm,
            ],
            [
                'enabled'      => '1',
                'name'         => 'community_DNSTAP_PROXY',
                'communities'  => '65103:777',
            ],
            [
                'enabled'      => '1',
                'name'         => 'community_DNSTAP_DIRECT',
                'communities'  => '65103:555',
            ],
            [
                'enabled'      => '1',
                'name'         => 'community_DNSTAP_BLOCK',
                'communities'  => '65103:888',
            ],
        ];
    }

    public static function liveNames(): array
    {
        $out = [];
        $mdl = new self();
        if (!method_exists($mdl->community, 'iterateItems')) {
            return $out;
        }
        foreach ($mdl->community->iterateItems() as $uuid => $item) {
            $out[(string)$uuid] = (string)$item->name;
        }
        natcasesort($out);
        return $out;
    }

    public static function overlayDropdownFromNames(array $names, $current): array
    {
        $selected = '';
        if (is_array($current)) {
            foreach ($current as $k => $v) {
                if (is_array($v) && !empty($v['selected'])) {
                    $selected = (string)$k;
                    break;
                }
            }
        } elseif (is_string($current) && $current !== '') {
            $selected = $current;
        }
        if ($selected !== '' && !isset($names[$selected])) {
            foreach ($names as $uuid => $name) {
                if (strcasecmp((string)$name, $selected) === 0) {
                    $selected = (string)$uuid;
                    break;
                }
            }
        }
        $out = [
            '' => ['value' => 'none', 'selected' => $selected === '' ? 1 : 0],
        ];
        foreach ($names as $uuid => $name) {
            $out[(string)$uuid] = [
                'value'    => (string)$name,
                'selected' => ((string)$uuid === $selected) ? 1 : 0,
            ];
        }
        return $out;
    }

    public static function overlayDropdown($current): array
    {
        return self::overlayDropdownFromNames(self::liveNames(), $current);
    }

    public function ensureDnstapProxyCommunity(): string
    {
        $want = 'community_DNSTAP_PROXY';
        $legacy = 'community_DNSTAP_BLOCKED';
        $legacyUuid = '';
        if (method_exists($this->community, 'iterateItems')) {
            foreach ($this->community->iterateItems() as $uuid => $item) {
                $n = trim((string)$item->name);
                if (strcasecmp($n, $want) === 0) {
                    return (string)$uuid;
                }
                if (strcasecmp($n, $legacy) === 0) {
                    $legacyUuid = (string)$uuid;
                }
            }
        }
        if ($legacyUuid !== '' && method_exists($this->community, 'iterateItems')) {
            $node = $this->community->{$legacyUuid};
            if ($node !== null && method_exists($node, 'setNodes')) {
                $node->setNodes(['name' => $want]);
            }
            $this->persistConfig();
            return $legacyUuid;
        }
        if (!method_exists($this->community, 'add')) {
            return '';
        }
        $uuid = $this->community->add();
        $node = $this->community->{$uuid};
        if ($node !== null && method_exists($node, 'setNodes')) {
            $node->setNodes([
                'enabled'     => '1',
                'name'        => $want,
                'communities' => '65103:777',
            ]);
        }
        $this->persistConfig();
        return (string)$uuid;
    }

    public function ensureDnstapBlockedCommunity(): string
    {
        return $this->ensureDnstapProxyCommunity();
    }

    public function ensureDnstapDirectCommunity(): string
    {
        $want = 'community_DNSTAP_DIRECT';
        if (method_exists($this->community, 'iterateItems')) {
            foreach ($this->community->iterateItems() as $uuid => $item) {
                if (strcasecmp(trim((string)$item->name), $want) === 0) {
                    return (string)$uuid;
                }
            }
        }
        if (!method_exists($this->community, 'add')) {
            return '';
        }
        $uuid = $this->community->add();
        $node = $this->community->{$uuid};
        if ($node !== null && method_exists($node, 'setNodes')) {
            $node->setNodes([
                'enabled'     => '1',
                'name'        => $want,
                'communities' => '65103:555',
            ]);
        }
        $this->persistConfig();
        return (string)$uuid;
    }

    public function ensureDnstapBlockCommunity(): string
    {
        $want = 'community_DNSTAP_BLOCK';
        if (method_exists($this->community, 'iterateItems')) {
            foreach ($this->community->iterateItems() as $uuid => $item) {
                if (strcasecmp(trim((string)$item->name), $want) === 0) {
                    return (string)$uuid;
                }
            }
        }
        if (!method_exists($this->community, 'add')) {
            return '';
        }
        $uuid = $this->community->add();
        $node = $this->community->{$uuid};
        if ($node !== null && method_exists($node, 'setNodes')) {
            $node->setNodes([
                'enabled'     => '1',
                'name'        => $want,
                'communities' => '65103:888',
            ]);
        }
        $this->persistConfig();
        return (string)$uuid;
    }

    public function seedDefaultCommunitiesIfEmpty(): void
    {
        if (method_exists($this->community, 'iterateItems')) {
            foreach ($this->community->iterateItems() as $item) {
                return;
            }
        }
        if (!method_exists($this->community, 'add')) {
            return;
        }
        foreach (self::builtinCommunityRows() as $data) {
            $uuid = $this->community->add();
            $node = $this->community->{$uuid};
            if ($node !== null && method_exists($node, 'setNodes')) {
                $node->setNodes($data);
            }
        }
        $this->persistConfig();
    }

    public function migrateCommunityFileNames(): void
    {
        if (!method_exists($this->community, 'iterateItems')) {
            return;
        }
        $changed = false;
        foreach ($this->community->iterateItems() as $item) {
            $n = trim((string)$item->name);
            if ($n === '' || strncasecmp($n, 'community_', 10) === 0) {
                continue;
            }
            $item->name = 'community_' . $n;
            $changed = true;
        }
        $script = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
        if (is_readable($script)) {
            require_once $script;
        }
        if (function_exists('xray_bgp_parse_communities') && function_exists('xray_bgp_format_communities_gui')) {
            foreach ($this->community->iterateItems() as $item) {
                $raw = trim((string)$item->communities);
                if ($raw === '') {
                    continue;
                }
                if (preg_match('/^\d+:\d+(\s*,\s*\d+:\d+)*$/', $raw)) {
                    continue;
                }
                $pairs = xray_bgp_parse_communities($raw);
                if ($pairs === []) {
                    continue;
                }
                $item->communities = xray_bgp_format_communities_gui($pairs);
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
    }
}
