**English** | [Русский](README.ru.md)

# os-xray

[![Repository](https://img.shields.io/badge/GitHub-ha--harbor--ws%2Fos--xray-blue)](https://github.com/ha-harbor-ws/os-xray)
[![Branch](https://img.shields.io/badge/branch-develop-green)](https://github.com/ha-harbor-ws/os-xray/tree/develop)
[![License](https://img.shields.io/github/license/ha-harbor-ws/os-xray)](https://github.com/ha-harbor-ws/os-xray/blob/develop/LICENSE)
[![OPNsense](https://img.shields.io/badge/OPNsense-25.x%20%2F%2026.x-blue)](https://opnsense.org)
[![FreeBSD](https://img.shields.io/badge/FreeBSD-14.x%20amd64-red)](https://freebsd.org)

**Xray-core VPN plugin for OPNsense** — v3.1.x (`develop`)

Xray-core + tun2socks native VPN client for OPNsense with selective routing, BIRD2/BGP, static prefix lists, DNStap, and automatic active-TUN selection.

---

## What changed vs a classic stack / early plugin builds

Compared with a manual xray-core + tun2socks setup and early single-instance GUI builds:

| Area | Added |
|------|--------|
| Instances | Multiple VPNs; per-instance SOCKS/TUN/DNS/IPv4/IPv6/weights |
| General | Routing strategy, BGP, Auto route, WAN4/WAN6, DNStap, Watchdog |
| Routing | BGP peers / filters / communities in GUI + `birdc configure` |
| Static | URL/geoip lists → `routes_static_v{4,6}_apply.inc` (PROXY/DIRECT/BLOCK) |
| DNStap | `os-dnstap-bgp` package, DNStap/Domains tabs, Unbound dnstap |
| Gateways | Auto-create/repair WAN + instance GWs; priority/`defaultgw` by strategy |
| Auto route | Cron + GW priority rewrite (**BLOCK→PROXY→DIRECT only**) |
| Host routes | OPNsense static routes via WAN to peer/server IPs (nslookup for hostnames) |
| Logs | `/var/log/xray-*.log`, bird, dnstap + newsyslog |
| Install | `uninstall` / **`fulluninstall`** (keeps unbound and `config.xml`) |

---

## Stack

```
xray-core (VLESS+Reality / custom outbound)
    ↓ SOCKS5 (per instance)
tun2socks → TUN (proxytun2socksN)
    ↓
OPNsense Gateways (WAN_GW + instance *_GW)
BIRD2: BGP + static PROXY/DIRECT/BLOCK → ACTIVE_TUN* / WAN
Unbound (DNStap) → dnstap-bgp → BIRD communities (PROXY/DIRECT/BLOCK)
Firewall / policy routing
```

---

## Requirements

| Component | Version |
|-----------|---------|
| OPNsense | 25.x / 26.x |
| FreeBSD | 14.x / 15.x amd64 |
| xray-core | 24.x+ (recommended) |
| tun2socks | Current FreeBSD amd64 |
| bird2 | FreeBSD pkg (via install.sh) |
| unbound | OPNsense / FreeBSD pkg |
| dnstap-bgp (os-dnstap-bgp) | GitHub pkg (optional) |

> **Warning:** When using **BGP** (BIRD2) and/or **DNStap BGP**, recommended system RAM is **at least 4 GB**. Lower memory may lead to swapping, slow DNS/route updates, or process kills under load.

---

## Install / uninstall

Branch **`develop`**.

```sh
cd /tmp
git clone -b develop https://github.com/ha-harbor-ws/os-xray.git
cd os-xray
sh install.sh
```

Archive:

```sh
fetch -o /tmp/os-xray.tar.gz https://github.com/ha-harbor-ws/os-xray/archive/refs/heads/develop.tar.gz
cd /tmp && tar xf os-xray.tar.gz && cd os-xray-develop
sh install.sh
```

Installer: plugin version, xray-core/tun2socks, **bird2**, **os-dnstap-bgp**, import legacy configs, syshooks, newsyslog, instance GW repair, autostart if Enable Xray is on.

| Command | Effect |
|---------|--------|
| `sh install.sh` | Install / upgrade |
| `sh install.sh uninstall` | Plugin; packages only if installed by os-xray (baseline) |
| `sh install.sh fulluninstall` | Plugin + BIRD/dnstap configs + logs + bird2/os-dnstap-bgp/tun2socks/xray-core. **Keeps unbound and `/conf/config.xml`** |

```sh
configctl xray version
```

---

## GUI tabs (VPN → Xray)

### Instances

Grid: xray/tun2socks status, Active TUN v4/v6, Test, Start/Stop.

| Option | Effect |
|--------|--------|
| **Enabled** | Included in start/reconfigure/Auto route |
| **Name** | Display; GW name base (`NAME_GW`) |
| **Outbound Config (JSON)** | Xray outbound; server address → WAN host-route |
| **SOCKS5 Listen / Port** | Inbound for tun2socks |
| **TUN Interface** | tun2socks device; must be assigned |
| **MTU** | TUN MTU |
| **IPv4 / IPv6** | Xray DNS/routing; does not forbid BIRD TUN pick |
| **DNS Servers** | Into instance config.json |
| **Weight IPv4/IPv6** | ACTIVE_TUN* preference when General weight toggles are on |
| **Bypass Networks** | CIDRs → `direct` outbound |
| **Log Level** | xray-core verbosity |

Dialog: Import VLESS, Validate Config. After add/set/del → sync WAN host-routes for the server IP/hostname.

**Instance gateways (System → Gateways):** created/repaired on BGP Apply, install repair, ensure.  
`fargw=1` always; **`defaultgw=1` only when Routing strategy = BLOCK→PROXY→DIRECT**, otherwise `0`.  
For PROXY-first (strategy + BGP + Auto route), WAN Upstream is cleared (`defaultgw=0`).  
Disabling BGP/Auto route, switching to System/DIRECT-first, or uninstall restores WAN `defaultgw=1`.

---

### General

| Option | Effect |
|--------|--------|
| **Enable Xray** | Global enable; Apply start/stop |
| **Enable Watchdog** | Cron restart of crashed processes → `/var/log/xray-watchdog.log` |
| **Routing strategy** | OPNsense GW priorities + (PROXY-first) autoroute priorities |
| **Enable BGP** | `bird_enable`; BIRD starts only if tun2socks is up and SOCKS OK |
| **Interface WAN4 / WAN6** | FreeBSD devices → `WAN4_IF` / `WAN6_IF`, WAN GW for DIRECT |
| **Enable DNStap BGP** | Unbound dnstap + start `dnstap-bgp` (if package present) |
| **Auto route** | Cron SOCKS polls; update `ACTIVE_TUN4/6_IF` |
| **Instance IPv4/IPv6 Weight** | Honour instance weights when picking TUN |
| **Auto route polls** | Probes per cycle (default 3) |
| **Auto route interval** | Minutes between cycles (default 10) |

**Apply** (bottom button): reconfigure instances, ensure gateways, WAN host-routes, BIRD peers, static protocols, DNStap. Does **not** run the Auto route poll loop (cron / Test auto route only).

#### Routing strategy → gateways

| Mode | WAN prio | WAN `defaultgw` | Instance prio | Instance `defaultgw` | Autoroute prio 100/201+ |
|------|----------|-----------------|---------------|----------------------|-------------------------|
| **System policy** / **BLOCK→DIRECT→PROXY** | 100 | **1** | 255 | **0** | no |
| **BLOCK→PROXY→DIRECT** + BGP + Auto route | 255 | **0** | 255 (active TUN → 100) | **1** | **yes** |
| PROXY-first but BGP or Auto route **off** / uninstall | 100 | **1** | 255 | **0** | no |

Lower OPNsense priority number = preferred.

---

### Routing → BGP peers

| Option | Effect |
|--------|--------|
| **Enabled** | In BIRD; Start/Stop → rewrite + BIRD restart |
| **Name** | Protocol / `peer_NAME.inc` |
| **Local / Neighbor AS** | BGP ASNs |
| **Neighbor** | IP or hostname; WAN host-routes for all A/AAAA; BIRD uses first resolved IP |
| **Source Address** | Peer `source address` (often WAN) |
| **IPv4/IPv6 + Import** | Families and filter |
| **Hold Time** | Hold timer |

Peer **`dnstap`** skips WAN host-routes (local session).

After add/set/del → `bgpwrite` + WAN routes sync.

---

### Routing → BGP filter / community

Filters: community + Interface (`ACTIVE_TUN4/6`, WAN4/WAN6, Null/blackhole).  
Communities feed filters and DNStap.

**Apply** on Routing: write includes + `birdc configure` (sessions stay up).

---

### Routing → Static

Enable static route → `static_v4` / `static_v6` protocols:

| Class | Next hop |
|-------|----------|
| **PROXY** | `via ACTIVE_TUN4_IF` / `ACTIVE_TUN6_IF` |
| **DIRECT** | IPv4: `via IPv4_GATEWAY`; IPv6: `via fe80::x%iface` / global / `via "iface"` |
| **BLOCK** | `blackhole` |

`IPv4_GATEWAY` / `IPv6_GATEWAY` in `wan_if.inc` are **WAN upstream** (not the system default via an instance TUN under PROXY-first). `wan_if.inc` is refreshed before every apply rebuild.

Prefix sources: URL lists and geoip.dat categories (PROXY/DIRECT/BLOCK).

---

### DNStap

Live `dnstap-bgp.conf` keys, PROXY/DIRECT/BLOCK lists, communities.  
Apply writes files + SIGHUP. Needs **Enable DNStap BGP** and the package.

---

### Domains

DNStap domain lists (URLs / extras). Apply = dnstap write/reload.

---

### Log

| Pane | File |
|------|------|
| Boot | `/var/log/xray-boot.log` |
| Autoroute | `/var/log/xray-autoroute.log` |
| Xray Core | per-instance / shared |
| Watchdog | `/var/log/xray-watchdog.log` |
| Bird | `/var/log/bird/bird.log` + syslog class |
| DNStap | `/var/log/dnstap-bgp/dnstap-bgp.log` |

---

### Diagnostics

TUN stats, RTT to server, uptime. Copy Debug Info for GitHub issues.

---

## Interface, gateway, NAT

1. **Interfaces → Assignments** — add TUN (`proxytun2socks0`, …)
2. Static `/30` (e.g. `10.255.0.1/30`), **Prevent interface removal**
3. Far-end gateway (`10.255.0.2`), Far Gateway, monitor off — or let the plugin ensure/repair
4. **Outbound NAT Hybrid** + rule on TUN (LAN → any, translation = Interface address)

Without Outbound NAT, LAN-sourced packets via TUN are dropped upstream.

---

## WAN host-routes (peer / server)

OPNsense **System → Routes** (not BIRD):

- BGP neighbor (except dnstap) → `/32`/`/128` via `WAN_GW` / `DefaultGW_IPv6`, descr `os-xray:bgp:NAME`
- Instance server (`vnext` address) → `os-xray:inst:UUID`
- Hostname → nslookup A+AAAA

When: peer/instance add/set/del; General Apply (BGP path); `configctl xray wanroutes`.

---

## CLI: `configctl xray …`

| Action | Purpose |
|--------|---------|
| `start` / `stop` / `restart` / `reconfigure` `[uuid]` | Service (all or one instance) |
| `status` `[uuid]` / `statusall` | Status |
| `version` | Plugin version |
| `validate` `[uuid]` | `xray -test` dry-run |
| `testconnect` `[uuid]` | HTTP via SOCKS |
| `ifstats` `[uuid]` | TUN stats |
| `delete` `uuid` | Stop + clean TUN/files |
| `watchdog` | Process check (cron) |
| `ifhealth` | IF test + optional ACTIVE_TUN reselect |
| `birdactivetun` | Schedule TUN selection (background) |
| `birdactivetunstatus` | Selection status |
| `birdautoroute` | One Auto route cycle |
| `birdsync` | Sync BIRD with tun2socks |
| `bgpwrite` / `bgprestart` / `bgpstatus` | Includes / restart / peer JSON |
| `wanroutes` | Sync WAN host-routes |
| `dnstap*` | DNStap get/write/start/stop/fetch/geodat |
| `static*` | Static lists get/write/fetch/geodat |
| `domainroute` `host\|ip` | Resolve + show BIRD routes |
| `log` / `autoroutelog` / `xraylog` / `birdlog` / `dnstaplog` | Log tails |
| `birdloglevel` `class` | BIRD syslog class |

Direct PHP (debug):

```sh
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-gateways.php repair|ensure
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-wan-static-routes.php sync
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-wan-static-routes.php lookup HOST
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-bird-active-tun.php autoroute
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-static-conf.php write
```

---

## Troubleshooting / error checks

| Issue | Check |
|-------|--------|
| Instance won’t start | Log → Boot / Xray Core; `configctl xray statusall`; Validate Config |
| Tunnel up but no traffic | Outbound NAT on TUN; Far Gateway; Prevent interface removal |
| BIRD won’t start | Enable BGP; tun2socks running; SOCKS HTTP 200–399; `birdsync` |
| `unexpected DEVICE` (static v6) | Old `device` apply — Static Apply / upgrade (`via …`) |
| `CF_SYM_UNDEFINED` / IPv4_GATEWAY | `wan_if.inc`; WAN4; General/Static Apply (wan_if written first) |
| DIRECT goes via PROXY | PROXY-first default via TUN; IPv4_GATEWAY is WAN-only |
| BGP peer / server unreachable “around” VPN | `wanroutes`; Routes descr `os-xray:` |
| Auto route doesn’t change GW priority | Only **BLOCK→PROXY→DIRECT**; Autoroute log |
| DNStap silent | Package? Enable DNStap BGP? Unbound dnstap; `dnstaplog` |
| Wrong `defaultgw` after upgrade | `xray-gateways.php repair` or reinstall (repair step) |

```sh
configctl xray statusall
configctl xray validate
configctl xray testconnect
configctl xray bgpstatus
birdc show protocols
birdc show route
tail -n 100 /var/log/xray-boot.log
tail -n 100 /var/log/xray-autoroute.log
tail -n 100 /var/log/bird/bird.log
```

Use **Copy Debug Info** on Diagnostics for GitHub issues.

---

## Boot

`rc.syshook.d/start`: hold BIRD → start Xray → birdsync. BIRD starts only when tun2socks is alive.

---

## Manual binaries

**xray-core:** [XTLS/Xray-core](https://github.com/XTLS/Xray-core/releases)

```sh
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip xray.zip xray
install -m 0755 /tmp/xray /usr/local/bin/xray-core
```

**tun2socks:** [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks/releases)

```sh
fetch -o /tmp/tun2socks.zip https://github.com/xjasonlyu/tun2socks/releases/latest/download/tun2socks-freebsd-amd64.zip
cd /tmp && unzip tun2socks.zip
mkdir -p /usr/local/tun2socks
install -m 0755 /tmp/tun2socks-freebsd-amd64 /usr/local/tun2socks/tun2socks
```

---

## Layout (short)

```
plugin/
  scripts/Xray/     — service-control, gateways, wan-static-routes, bird-*, static-*, dnstap-*
  service/.../actions_xray.conf
  mvc/.../Xray/     — models, API, tabs
  etc/rc.syshook.d/start/
  etc/newsyslog.conf.d/xray.conf
bird.conf, bird/*.inc, bgp.conf, install.sh
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

| Version | Notes |
|---------|--------|
| 3.1.x develop | Gateways/strategy/defaultgw, WAN host-routes, autoroute prio PROXY-first only, WAN IPv4_GATEWAY (not TUN), IPv6 DIRECT via, fulluninstall, install repair |
| 3.1.0 | BIRD2 GUI, Auto route, DNStap package, gated BIRD start |
| 3.0.0 | Multi-instance |
| 2.x / 1.x | Custom config, watchdog, diagnostics, VLESS import |

---

## License

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich

---

## Author

Merkulov Pavel Sergeevich  
February — 2026

---

## Thanks

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core)
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks)
- [OPNsense](https://opnsense.org)
- [yukh975](https://github.com/yukh975), [hohner36](https://github.com/hohner36) — testing
