[English](README.md) | **Русский**

# os-xray

[![Repository](https://img.shields.io/badge/GitHub-ha--harbor--ws%2Fos--xray-blue)](https://github.com/ha-harbor-ws/os-xray)
[![Branch](https://img.shields.io/badge/branch-develop-green)](https://github.com/ha-harbor-ws/os-xray/tree/develop)
[![License](https://img.shields.io/github/license/ha-harbor-ws/os-xray)](https://github.com/ha-harbor-ws/os-xray/blob/develop/LICENSE)
[![OPNsense](https://img.shields.io/badge/OPNsense-25.x%20%2F%2026.x-blue)](https://opnsense.org)
[![FreeBSD](https://img.shields.io/badge/FreeBSD-14.x%20amd64-red)](https://freebsd.org)

**Xray-core VPN plugin for OPNsense** — v3.1.x (`develop`)

Xray-core + tun2socks — нативный VPN-клиент для OPNsense с селективной маршрутизацией, BIRD2/BGP, static-префиксами, DNStap и автовыбором активного TUN.

---

## Отличия от «классического» стека и ранних версий плагина

По сравнению с ручной связкой xray-core + tun2socks и ранним GUI (только один инстанс / без BGP):

| Область | Что добавлено |
|---------|----------------|
| Инстансы | Несколько VPN, per-instance SOCKS/TUN/DNS/IPv4/IPv6/weights |
| General | Routing strategy, BGP, Auto route, WAN4/WAN6, DNStap, Watchdog |
| Routing | BGP peers / filters / communities в GUI + `birdc configure` |
| Static | URL/geoip списки → `routes_static_v{4,6}_apply.inc` (PROXY/DIRECT/BLOCK) |
| DNStap | Пакет `os-dnstap-bgp`, вкладка Domains/DNStap, Unbound dnstap |
| Gateways | Автосоздание/repair WAN + instance GW; priority/`defaultgw` по strategy |
| Auto route | Cron + приоритеты GW (только BLOCK→PROXY→DIRECT) |
| Host routes | Static routes через WAN на IP peer/server (nslookup для hostname) |
| Логи | `/var/log/xray-*.log`, bird, dnstap + newsyslog |
| Install | `uninstall` / **`fulluninstall`** (без удаления unbound и `config.xml`) |

---

## Стек

```
xray-core (VLESS+Reality / custom outbound)
    ↓ SOCKS5 (на инстанс)
tun2socks → TUN (proxytun2socksN)
    ↓
OPNsense Gateways (WAN_GW + instance *_GW)
BIRD2: BGP + static PROXY/DIRECT/BLOCK → ACTIVE_TUN* / WAN
Unbound (DNStap) → dnstap-bgp → BIRD communities (PROXY/DIRECT/BLOCK)
Firewall / policy routing
```

---

## Системные требования

| Компонент | Версия |
|-----------|--------|
| OPNsense | 25.x / 26.x |
| FreeBSD | 14.x / 15.x amd64 |
| xray-core | 24.x+ (рекомендуется) |
| tun2socks | Актуальный FreeBSD amd64 |
| bird2 | FreeBSD pkg (ставит install.sh) |
| unbound | OPNsense / FreeBSD pkg |
| dnstap-bgp (os-dnstap-bgp) | GitHub pkg (опционально) |

---

## Установка и удаление

Ветка **`develop`**.

```sh
cd /tmp
git clone -b develop https://github.com/ha-harbor-ws/os-xray.git
cd os-xray
sh install.sh
```

Архив:

```sh
fetch -o /tmp/os-xray.tar.gz https://github.com/ha-harbor-ws/os-xray/archive/refs/heads/develop.tar.gz
cd /tmp && tar xf os-xray.tar.gz && cd os-xray-develop
sh install.sh
```

Установщик: версия плагина, xray-core/tun2socks, **bird2**, **os-dnstap-bgp**, import старых конфигов, syshooks, newsyslog, repair instance GW, автостарт при Enable Xray.

| Команда | Действие |
|---------|----------|
| `sh install.sh` | Установка / обновление |
| `sh install.sh uninstall` | Плагин; пакеты — только если ставил os-xray (baseline) |
| `sh install.sh fulluninstall` | Плагин + конфиги BIRD/dnstap + логи + bird2/os-dnstap-bgp/tun2socks/xray-core. **Оставляет unbound и `/conf/config.xml`** |

```sh
configctl xray version
```

---

## GUI: вкладки (VPN → Xray)

### Instances

Таблица инстансов: статус xray/tun2socks, Active TUN v4/v6, Test, Start/Stop.

| Параметр | Влияние |
|----------|---------|
| **Enabled** | Участвует в start/reconfigure/Auto route |
| **Name** | Отображение; имя GW (`NAME_GW`) |
| **Outbound Config (JSON)** | Outbound xray; address сервера → WAN host-route |
| **SOCKS5 Listen / Port** | Inbound для tun2socks |
| **TUN Interface** | Устройство tun2socks; должно быть в Assignments |
| **MTU** | MTU TUN |
| **IPv4 / IPv6** | DNS/routing в xray; не запрещают выбор TUN для BIRD |
| **DNS Servers** | DNS в config.json инстанса |
| **Weight IPv4/IPv6** | Приоритет при выборе ACTIVE_TUN*, если включены галки Weight в General |
| **Bypass Networks** | CIDR → outbound `direct` |
| **Log Level** | Лог xray-core |

Диалог: Import VLESS, Validate Config. После add/set/del — sync WAN static routes на server IP/hostname.

**Gateway instance (System → Gateways):** создаются/чинятся при BGP Apply, install repair, ensure.  
`fargw=1` всегда; **`defaultgw=1` только при Routing strategy = BLOCK→PROXY→DIRECT**, иначе `0`.  
При PROXY-first (strategy + BGP + Auto route) у WAN снимается Upstream (`defaultgw=0`).  
При выключении BGP/Auto route, смене strategy на System/DIRECT-first или uninstall WAN снова получает `defaultgw=1`.

---

### General

| Параметр | Влияние |
|----------|---------|
| **Enable Xray** | Глобальный enable; Apply start/stop |
| **Enable Watchdog** | Cron: перезапуск упавших процессов → `/var/log/xray-watchdog.log` |
| **Routing strategy** | Приоритеты OPNsense GW + (для PROXY-first) autoroute priorities. См. ниже |
| **Enable BGP** | `bird_enable`; старт BIRD только если жив tun2socks и SOCKS OK |
| **Interface WAN4 / WAN6** | FreeBSD device → `WAN4_IF` / `WAN6_IF`, детект WAN GW для DIRECT |
| **Enable DNStap BGP** | Unbound dnstap + старт `dnstap-bgp` (если пакет есть) |
| **Auto route** | Cron: опросы SOCKS, смена `ACTIVE_TUN4/6_IF` |
| **Instance IPv4/IPv6 Weight** | Учитывать weight инстанса при выборе TUN |
| **Auto route polls** | Число опросов в цикле (по умолчанию 3) |
| **Auto route interval** | Минуты между циклами (по умолчанию 10) |

**Apply (кнопка внизу):** reconfigure инстансов, gateways ensure, WAN host-routes sync, BIRD peers, static protocols, DNStap. Цикл Auto route с Apply **не** запускается (только cron / кнопка Test auto route).

#### Routing strategy → шлюзы

| Режим | WAN priority | WAN `defaultgw` | Instance priority | Instance `defaultgw` | Auto route → priority 100/201+ |
|-------|--------------|-----------------|-------------------|----------------------|--------------------------------|
| **System policy** / **BLOCK→DIRECT→PROXY** | 100 | **1** | 255 | **0** | нет |
| **BLOCK→PROXY→DIRECT** + BGP + Auto route | 255 | **0** | 255 (active TUN → 100) | **1** | **да** |
| PROXY-first, но BGP или Auto route **выкл** / uninstall | 100 | **1** | 255 | **0** | нет |

Меньше priority в OPNsense = выше предпочтение.

---

### Routing → BGP peers

| Параметр | Влияние |
|----------|---------|
| **Enabled** | Include в BIRD; Start/Stop → rewrite + restart BIRD |
| **Name** | Имя protocol / `peer_NAME.inc` |
| **Local / Neighbor AS** | BGP AS |
| **Neighbor** | IP или hostname; host-routes via WAN на все A/AAAA; BIRD — первый resolved IP |
| **Source Address** | `source address` в peer (часто WAN) |
| **IPv4/IPv6 + Import** | Семейства и filter |
| **Hold Time** | Hold time |

Peer **`dnstap`** — без WAN host-routes (локальная сессия).

После add/set/del → `bgpwrite` + sync WAN routes.

---

### Routing → BGP filter / community

Фильтры: community + Interface (`ACTIVE_TUN4/6`, WAN4/WAN6, Null/blackhole).  
Communities — define для фильтров и DNStap.

**Apply** на Routing: запись includes + `birdc configure` (сессии не рвутся).

---

### Routing → Static

Enable static route → protocol `static_v4` / `static_v6` с includes:

| Класс | Next hop |
|-------|----------|
| **PROXY** | `via ACTIVE_TUN4_IF` / `ACTIVE_TUN6_IF` |
| **DIRECT** | IPv4: `via IPv4_GATEWAY`; IPv6: `via fe80::x%iface` / global / `via "iface"` |
| **BLOCK** | `blackhole` |

`IPv4_GATEWAY` / `IPv6_GATEWAY` в `wan_if.inc` — **WAN upstream** (не system default через instance TUN при PROXY-first). Перед сборкой apply всегда обновляется `wan_if.inc`.

Источники префиксов: URL-листы и geoip.dat categories (PROXY/DIRECT/BLOCK).

---

### DNStap

Живые ключи `dnstap-bgp.conf`, списки PROXY/DIRECT/BLOCK, community.  
Apply → запись файлов + SIGHUP. Зависит от **Enable DNStap BGP** и пакета.

---

### Domains

Списки доменов для DNStap (URL / extra). Apply = запись/reload dnstap (dnstapwrite).

---

### Log

| Лог | Файл |
|-----|------|
| Boot | `/var/log/xray-boot.log` |
| Autoroute | `/var/log/xray-autoroute.log` |
| Xray Core | per-instance / общий |
| Watchdog | `/var/log/xray-watchdog.log` |
| Bird | `/var/log/bird/bird.log` + класс syslog |
| DNStap | `/var/log/dnstap-bgp/dnstap-bgp.log` |

---

### Diagnostics

Статистика TUN, RTT до server, uptime. Copy Debug Info — сводка для issue.

---

## Интерфейс, шлюз, NAT

1. **Interfaces → Assignments** — добавить TUN (`proxytun2socks0` и др.)
2. Static IP `/30` (напр. `10.255.0.1/30`), **Prevent interface removal**
3. Gateway far end (`10.255.0.2`), Far Gateway, monitor off — или доверить плагину (ensure/repair)
4. **Outbound NAT Hybrid** + правило на TUN (LAN → any, translation = Interface address)

Без Outbound NAT трафик с LAN-адресами через TUN отбрасывается.

---

## WAN host-routes (peer / server)

OPNsense **System → Routes** (не BIRD):

- BGP neighbor (кроме dnstap) → `/32`/`/128` via `WAN_GW` / `DefaultGW_IPv6`, descr `os-xray:bgp:NAME`
- Instance server (vnext address) → `os-xray:inst:UUID`
- Hostname → nslookup A+AAAA

Когда: add/set/del peer или instance; General Apply (BGP); `configctl xray wanroutes`.

---

## CLI: `configctl xray …`

| Action | Назначение |
|--------|------------|
| `start` / `stop` / `restart` / `reconfigure` `[uuid]` | Сервис (все или один инстанс) |
| `status` `[uuid]` / `statusall` | Статус |
| `version` | Версия плагина |
| `validate` `[uuid]` | `xray -test` без применения |
| `testconnect` `[uuid]` | HTTP через SOCKS |
| `ifstats` `[uuid]` | Статистика TUN |
| `delete` `uuid` | Остановка + очистка TUN/файлов |
| `watchdog` | Проверка процессов (cron) |
| `ifhealth` | Test IF + возможный reselect ACTIVE_TUN |
| `birdactivetun` | Планирует выбор TUN (фон) |
| `birdactivetunstatus` | Статус выбора |
| `birdautoroute` | Один цикл Auto route |
| `birdsync` | Синхронизация BIRD с tun2socks |
| `bgpwrite` / `bgprestart` / `bgpstatus` | Includes / restart / JSON статус пиров |
| `wanroutes` | Sync host-routes via WAN |
| `dnstapconf` / `dnstapwrite` / `start` / `stop` / `fetch` / `fetchone` / `geodat` | DNStap |
| `staticconf` / `staticwrite` / `staticfetch` / `staticfetchone` / `staticgeodat` | Static lists |
| `domainroute` `host\|ip` | Resolve + показать маршруты BIRD |
| `log` / `autoroutelog` / `xraylog` / `birdlog` / `dnstaplog` | Хвосты логов |
| `birdloglevel` `class` | Класс syslog BIRD |

Прямой PHP (отладка):

```sh
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-gateways.php repair|ensure
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-wan-static-routes.php sync
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-wan-static-routes.php lookup HOST
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-bird-active-tun.php autoroute
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-static-conf.php write
```

---

## Проверка ошибок и диагностика

| Симптом / задача | Что смотреть |
|------------------|--------------|
| Инстанс не поднимается | Log → Boot / Xray Core; `configctl xray statusall`; Validate Config |
| SOCKS/туннель «жив», но нет сети | Outbound NAT на TUN; Gateway Far Gateway; assignment Prefer interface removal |
| BIRD не стартует | Enable BGP; tun2socks running; SOCKS HTTP 200–399; `configctl xray birdsync` |
| `unexpected DEVICE` в static v6 | Старый apply с `device` — Static Apply / обновление плагина (`via …`) |
| `CF_SYM_UNDEFINED` / IPv4_GATEWAY | `cat /usr/local/etc/bird/wan_if.inc`; WAN4; General Apply / Static Apply (сначала пишется wan_if) |
| DIRECT уходит в PROXY | Strategy PROXY-first + default через TUN; IPv4_GATEWAY берётся с WAN, не с default route через instance |
| Пир BGP / сервер недоступен «мимо VPN» | `configctl xray wanroutes`; Routes → descr `os-xray:` |
| Auto route не меняет GW priority | Только при **BLOCK→PROXY→DIRECT**; лог Autoroute |
| DNStap молчит | Пакет установлен? Enable DNStap BGP? Unbound dnstap.conf; `configctl xray dnstaplog` |
| После обновления GW `defaultgw` | `php …/xray-gateways.php repair` или reinstall (шаг repair) |

Полезные команды:

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
grep os-xray /conf/config.xml | head
```

Copy Debug Info на вкладке Diagnostics — для issue на GitHub.

---

## Автозапуск после ребута

`rc.syshook.d/start`: hold BIRD → старт Xray → birdsync. BIRD поднимается только при живом tun2socks.

---

## Если бинарники ещё не установлены

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

## Структура (кратко)

```
plugin/
  scripts/Xray/     — service-control, gateways, wan-static-routes, bird-*, static-*, dnstap-*
  service/.../actions_xray.conf
  mvc/.../Xray/     — модели, API, вкладки
  etc/rc.syshook.d/start/  — 10-hold, 50-xray, 99-bird
  etc/newsyslog.conf.d/xray.conf
bird.conf, bird/*.inc, bgp.conf, install.sh
```

---

## Changelog

Полная история — [CHANGELOG.md](CHANGELOG.md).

| Версия | Кратко |
|--------|--------|
| 3.1.x develop | Gateways/strategy/defaultgw, WAN host-routes, autoroute prio только PROXY-first, IPv4_GATEWAY с WAN (не TUN), IPv6 DIRECT via, fulluninstall, repair на install |
| 3.1.0 | BIRD2 GUI, Auto route, DNStap package, conditional BIRD start |
| 3.0.0 | Мульти-инстанс |
| 2.x / 1.x | Custom config, watchdog, diagnostics, import VLESS |

---

## Лицензия

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich (Меркулов Павел Сергеевич)

---

## Автор

Меркулов Павел Сергеевич  
Февраль — 2026

---

## Благодарности

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core)
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks)
- [OPNsense](https://opnsense.org)
- [yukh975](https://github.com/yukh975), [hohner36](https://github.com/hohner36) — тестирование
