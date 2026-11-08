<?php

namespace OPNsense\Xray;

use OPNsense\Base\BaseModel;
use OPNsense\Core\Config;

class BgpFilter extends BaseModel
{
    /**
     * @return list<array{community:string,tun_if:string}>
     */
    public static function parseBlocks($item): array
    {
        $community = '';
        $tunIf     = '';
        $raw       = '';
        if (is_array($item)) {
            $community = (string)($item['community'] ?? '');
            $tunIf     = (string)($item['tun_if'] ?? '');
            $raw       = (string)($item['blocks'] ?? '');
        } elseif (is_object($item)) {
            $community = (string)($item->community ?? '');
            $tunIf     = (string)($item->tun_if ?? '');
            $raw       = (string)($item->blocks ?? '');
        }
        $blocks = [];
        $raw = trim($raw);
        if ($raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                foreach ($j as $b) {
                    if (!is_array($b)) {
                        continue;
                    }
                    $blocks[] = [
                        'community' => (string)($b['community'] ?? ''),
                        'tun_if'    => (string)($b['tun_if'] ?? ''),
                    ];
                }
            }
        }
        if ($blocks === []) {
            $blocks[] = [
                'community' => $community,
                'tun_if'    => $tunIf,
            ];
        }
        return $blocks;
    }

    public static function normalizeTunIf(string $tun, string $family = 'ipv4'): string
    {
        $tun = trim($tun);
        $low = strtolower($tun);
        $v6  = ($family === 'ipv6');
        if ($tun === '' || $low === 'active_tun') {
            return $v6 ? 'ACTIVE_TUN6_IF' : 'ACTIVE_TUN4_IF';
        }
        if ($low === 'wan' || $low === 'wan_if') {
            return $v6 ? 'WAN6' : 'WAN4';
        }
        if ($low === 'wan4' || $low === 'wan4_if') {
            return 'WAN4';
        }
        if ($low === 'wan6' || $low === 'wan6_if') {
            return 'WAN6';
        }
        if ($low === 'null' || $low === 'null4' || $low === 'null6'
            || $low === 'null4_if' || $low === 'null6_if' || $low === 'null_if') {
            return 'Null';
        }
        return $tun;
    }

    public static function builtinFilterRows(): array
    {
        return [
            [
                'enabled'        => '1',
                'name'           => 'filter_refilter',
                'community'      => '',
                'family'         => 'ipv4',
                'tun_if'         => 'ACTIVE_TUN4_IF',
            ],
            [
                'enabled'        => '1',
                'name'           => 'filter_antifilter_download',
                'community'      => 'community_ANTIFILTER_DOWNLOAD',
                'family'         => 'ipv4',
                'tun_if'         => 'ACTIVE_TUN4_IF',
            ],
            [
                'enabled'        => '1',
                'name'           => 'filter_antifilter_network_v4',
                'community'      => 'community_ANTIFILTER_NETWORK',
                'family'         => 'ipv4',
                'tun_if'         => 'ACTIVE_TUN4_IF',
            ],
            [
                'enabled'        => '1',
                'name'           => 'filter_antifilter_network_v6',
                'community'      => 'community_ANTIFILTER_NETWORK',
                'family'         => 'ipv6',
                'tun_if'         => 'ACTIVE_TUN6_IF',
            ],
            [
                'enabled'        => '1',
                'name'           => 'filter_dnstap_v4',
                'community'      => 'community_DNSTAP_PROXY',
                'family'         => 'ipv4',
                'tun_if'         => 'ACTIVE_TUN4_IF',
            ],
            [
                'enabled'        => '1',
                'name'           => 'filter_dnstap_v6',
                'community'      => 'community_DNSTAP_PROXY',
                'family'         => 'ipv6',
                'tun_if'         => 'ACTIVE_TUN6_IF',
            ],
        ];
    }

    public static function liveNames(): array
    {
        $out = [];
        $mdl = new self();
        if (!method_exists($mdl->filter, 'iterateItems')) {
            return $out;
        }
        foreach ($mdl->filter->iterateItems() as $uuid => $item) {
            $out[(string)$uuid] = (string)$item->name;
        }
        natcasesort($out);
        return $out;
    }

    public static function overlayDropdown($current): array
    {
        return BgpCommunity::overlayDropdownFromNames(self::liveNames(), $current);
    }

    public function filterUuidByName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !method_exists($this->filter, 'iterateItems')) {
            return '';
        }
        foreach ($this->filter->iterateItems() as $uuid => $item) {
            if (strcasecmp(trim((string)$item->name), $name) === 0) {
                return (string)$uuid;
            }
        }
        return '';
    }

    public function ensureDnstapFilters(): void
    {
        $commMdl = new BgpCommunity();
        $proxyUuid = $commMdl->ensureDnstapBlockedCommunity();
        $directUuid = $commMdl->ensureDnstapDirectCommunity();
        $blockUuid = $commMdl->ensureDnstapBlockCommunity();
        $want = [
            'filter_dnstap_v4' => [
                'family' => 'ipv4',
                'tun_if' => 'ACTIVE_TUN4_IF',
                'wan_if' => 'WAN4',
            ],
            'filter_dnstap_v6' => [
                'family' => 'ipv6',
                'tun_if' => 'ACTIVE_TUN6_IF',
                'wan_if' => 'WAN6',
            ],
        ];
        $have = [];
        $changed = false;
        if (method_exists($this->filter, 'iterateItems')) {
            foreach ($this->filter->iterateItems() as $item) {
                $n = trim((string)$item->name);
                $have[$n] = true;
                if (!isset($want[$n])) {
                    continue;
                }
                if ((string)$item->enabled !== '1') {
                    $item->enabled = '1';
                    $changed = true;
                }
                if ($proxyUuid !== '' && (string)$item->community !== $proxyUuid) {
                    $item->community = $proxyUuid;
                    $changed = true;
                }
                if ((string)$item->family !== $want[$n]['family']) {
                    $item->family = $want[$n]['family'];
                    $changed = true;
                }
                // Seed PROXY→TUN, DIRECT→WAN, BLOCK→Null when DIRECT/BLOCK blocks are missing.
                $blocks = self::parseBlocks($item);
                $ids = [];
                foreach ($blocks as $b) {
                    $c = trim((string)($b['community'] ?? ''));
                    if ($c !== '') {
                        $ids[$c] = true;
                    }
                }
                $needDirect = $directUuid !== '' && !isset($ids[$directUuid]);
                $needBlock = $blockUuid !== '' && !isset($ids[$blockUuid]);
                if ($needDirect || $needBlock) {
                    $proxyTun = trim((string)($item->tun_if ?? '')) !== ''
                        ? (string)$item->tun_if
                        : $want[$n]['tun_if'];
                    $full = [
                        [
                            'community' => $proxyUuid !== '' ? $proxyUuid : 'community_DNSTAP_PROXY',
                            'tun_if'    => $proxyTun,
                        ],
                    ];
                    foreach ($blocks as $b) {
                        $c = trim((string)($b['community'] ?? ''));
                        if ($c === '' || $c === $proxyUuid) {
                            continue;
                        }
                        if ($c === $directUuid || $c === $blockUuid) {
                            continue;
                        }
                        $full[] = [
                            'community' => $c,
                            'tun_if'    => (string)($b['tun_if'] ?? $proxyTun),
                        ];
                    }
                    if ($directUuid !== '') {
                        $full[] = [
                            'community' => $directUuid,
                            'tun_if'    => $want[$n]['wan_if'],
                        ];
                    }
                    if ($blockUuid !== '') {
                        $full[] = [
                            'community' => $blockUuid,
                            'tun_if'    => 'Null',
                        ];
                    }
                    $item->blocks = json_encode($full);
                    $item->tun_if = $proxyTun;
                    $changed = true;
                }
            }
        }
        foreach ($want as $name => $meta) {
            if (!empty($have[$name])) {
                continue;
            }
            if (!method_exists($this->filter, 'add')) {
                return;
            }
            $uuid = $this->filter->add();
            $node = $this->filter->{$uuid};
            if ($node !== null && method_exists($node, 'setNodes')) {
                $blocks = [
                    [
                        'community' => $proxyUuid !== '' ? $proxyUuid : 'community_DNSTAP_PROXY',
                        'tun_if'    => $meta['tun_if'],
                    ],
                ];
                if ($directUuid !== '') {
                    $blocks[] = [
                        'community' => $directUuid,
                        'tun_if'    => $meta['wan_if'],
                    ];
                }
                if ($blockUuid !== '') {
                    $blocks[] = [
                        'community' => $blockUuid,
                        'tun_if'    => 'Null',
                    ];
                }
                $node->setNodes([
                    'enabled'   => '1',
                    'name'      => $name,
                    'community' => $proxyUuid !== '' ? $proxyUuid : 'community_DNSTAP_PROXY',
                    'family'    => $meta['family'],
                    'tun_if'    => $meta['tun_if'],
                    'blocks'    => json_encode($blocks),
                ]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->persistConfig();
        }
    }

    public function seedDefaultFiltersIfEmpty(): void
    {
        if (method_exists($this->filter, 'iterateItems')) {
            foreach ($this->filter->iterateItems() as $item) {
                return;
            }
        }
        if (!method_exists($this->filter, 'add')) {
            return;
        }
        foreach (self::builtinFilterRows() as $data) {
            $uuid = $this->filter->add();
            $node = $this->filter->{$uuid};
            if ($node !== null && method_exists($node, 'setNodes')) {
                $node->setNodes($data);
            }
        }
        $this->persistConfig();
    }

    public function migrateAcceptFilterNames(): void
    {
        if (!method_exists($this->filter, 'iterateItems')) {
            return;
        }
        $changed = false;
        foreach ($this->filter->iterateItems() as $item) {
            $fam = ((string)$item->family === 'ipv6') ? 'ipv6' : 'ipv4';
            $tif = self::normalizeTunIf((string)$item->tun_if, $fam);
            if ($tif !== (string)$item->tun_if) {
                $item->tun_if = $tif;
                $changed = true;
            }
            $blocks = self::parseBlocks($item);
            $fixed  = [];
            $bchg   = false;
            foreach ($blocks as $b) {
                $nt = self::normalizeTunIf((string)($b['tun_if'] ?? ''), $fam);
                if ($nt !== (string)($b['tun_if'] ?? '')) {
                    $bchg = true;
                }
                $fixed[] = [
                    'community' => (string)($b['community'] ?? ''),
                    'tun_if'    => $nt,
                ];
            }
            if ($bchg) {
                $item->blocks = json_encode($fixed);
                $changed = true;
            }
        }
        foreach ($this->filter->iterateItems() as $item) {
            $n = (string)$item->name;
            if (strncasecmp($n, 'accept_', 7) !== 0) {
                continue;
            }
            $item->name = 'filter_' . substr($n, 7);
            $changed = true;
        }
        $byName = [];
        $uuids  = [];
        $comms = new BgpCommunity();
        if (method_exists($comms->community, 'iterateItems')) {
            foreach ($comms->community->iterateItems() as $uuid => $item) {
                $n = (string)$item->name;
                $byName[$n] = $uuid;
                $uuids[$uuid] = true;
            }
        }
        foreach ($this->filter->iterateItems() as $item) {
            $c = trim((string)$item->community);
            if ($c === '' || strcasecmp($c, 'none') === 0) {
                if ($c !== '') {
                    $item->community = '';
                    $changed = true;
                }
                continue;
            }
            if (isset($uuids[$c])) {
                continue;
            }
            if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $c)) {
                continue;
            }
            if (strncasecmp($c, 'community_', 10) === 0) {
                $rest = substr($c, 10);
                if (isset($uuids[$rest])) {
                    $item->community = $rest;
                    $changed = true;
                    continue;
                }
                if (isset($byName[$c])) {
                    $item->community = $byName[$c];
                    $changed = true;
                    continue;
                }
                if (isset($byName[$rest])) {
                    $item->community = $byName[$rest];
                    $changed = true;
                }
                continue;
            }
            $prefixed = 'community_' . $c;
            if (isset($byName[$prefixed])) {
                $item->community = $byName[$prefixed];
                $changed = true;
                continue;
            }
            if (isset($byName[$c])) {
                $item->community = $byName[$c];
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
        if (method_exists(BgpCommunity::class, 'flushCacheData')) {
            BgpCommunity::flushCacheData();
        }
    }
}
