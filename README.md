# os-xray

**Xray-core (VLESS+Reality) VPN plugin for OPNsense**

Xray-core с протоколом VLESS+Reality + tun2socks — нативный VPN-клиент для OPNsense с поддержкой селективной маршрутизации. Обходит DPI-блокировки за счёт маскировки трафика под легитимный TLS.

---

## Возможности

- Импорт VLESS ссылки одной кнопкой — **Import VLESS link → Parse & Fill**
- Полная поддержка параметров VLESS+Reality (UUID, flow, SNI, PublicKey, ShortID, Fingerprint)
- Управление туннелем через GUI: **VPN → Xray**
- При установке автоматически определяет и импортирует существующий конфиг xray-core и tun2socks
- Совместимость с селективной маршрутизацией OPNsense (Firewall Aliases + Rules + Gateway)
- Статус сервисов xray-core и tun2socks отображается прямо в GUI

## Стек

```
xray-core (VLESS+Reality)
    ↓ SOCKS5 127.0.0.1:10808
tun2socks
    ↓ TUN интерфейс proxytun2socks0
OPNsense Gateway PROXYTUN_GW
    ↓
Firewall Rules (селективная маршрутизация)
```

## Системные требования

| Компонент | Версия |
| --- | --- |
| OPNsense | 25.x |
| FreeBSD | 14.3-RELEASE amd64 |
| xray-core | Любая актуальная |
| tun2socks | Любая актуальная |

---

## Установка

```sh
fetch -o /tmp/os-xray.tar https://github.com/MrTheory/os-xray/releases/latest/download/os-xray.tar
cd /tmp && tar xf os-xray.tar && cd os-xray
sh install.sh
```

Скрипт автоматически:
- Проверит наличие бинарников `xray-core` и `tun2socks` — если их нет, выведет ссылки для скачивания
- Найдёт существующие конфиги и импортирует их в OPNsense (поля в GUI заполнятся сразу)
- Скопирует все файлы плагина, перезапустит configd, очистит кеши

### Настройка в GUI

1. Обнови браузер **(Ctrl+F5)** → **VPN → Xray**
2. Вкладка **Instance** → кнопка **Import VLESS link** → вставь ссылку → **Parse & Fill**
3. Вкладка **General** → установи галку **Enable Xray**
4. Нажми **Apply**

### Интерфейс и шлюз

| Шаг | Путь в GUI | Значение |
| --- | --- | --- |
| Назначить интерфейс | Interfaces → Assignments | + Add: `proxytun2socks0` |
| Включить и настроить | Interfaces → \<имя\> | Enable ✓, IPv4: Static, IP: `10.255.0.1/30` |
| Создать шлюз | System → Gateways → Add | Gateway IP: `10.255.0.1`, Far Gateway ✓, Monitoring off ✓ |

### Селективная маршрутизация

- **Firewall → Aliases** — создай список IP/сетей/доменов для маршрутизации через VPN
- **Firewall → Rules → LAN** — добавь правило: Source = LAN net, Destination = alias, Gateway = PROXYTUN_GW

> MSS Clamping для Xray не требуется (в отличие от WireGuard).

---

## Удаление

```sh
cd /tmp/os-xray
sh install.sh uninstall
```

---

## Устранение неполадок

**Меню VPN → Xray не появляется**
```sh
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5
```

**Проверить статус сервисов**
```sh
/usr/local/opnsense/scripts/Xray/xray-service-control.php status
# {"status":"ok","xray_core":"running","tun2socks":"running"}
```

**Просмотреть лог ошибок**
```sh
cat /var/lib/php/tmp/PHP_errors.log | tail -30
```

**Конфиги генерируются при нажатии Apply:**
```
/usr/local/etc/xray-core/config.json
/usr/local/tun2socks/config.yaml
```

---

## Структура файлов

```
os-xray/
├── install.sh
└── plugin/
    ├── etc/inc/plugins.inc.d/
    │   └── xray.inc                        ← регистрация сервиса в OPNsense
    ├── scripts/Xray/
    │   └── xray-service-control.php        ← управление xray-core и tun2socks
    ├── service/conf/actions.d/
    │   └── actions_xray.conf               ← configd actions
    └── mvc/app/
        ├── models/OPNsense/Xray/
        │   ├── General.xml / General.php   ← модель: enable/disable
        │   ├── Instance.xml / Instance.php ← модель: параметры подключения
        │   └── Menu/Menu.xml               ← пункт меню VPN → Xray
        ├── controllers/OPNsense/Xray/
        │   ├── IndexController.php
        │   ├── forms/general.xml
        │   ├── forms/instance.xml
        │   └── Api/
        │       ├── GeneralController.php
        │       ├── InstanceController.php
        │       ├── ServiceController.php
        │       └── ImportController.php    ← парсинг VLESS ссылки
        └── views/OPNsense/Xray/
            └── general.volt
```

---

## Если бинарники ещё не установлены

Скрипт установки сам сообщит об отсутствии бинарников. Ниже — команды для их ручной установки.

**xray-core** — [github.com/XTLS/Xray-core/releases](https://github.com/XTLS/Xray-core/releases)
```sh
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip xray.zip xray
install -m 0755 /tmp/xray /usr/local/bin/xray-core
```

**tun2socks** — [github.com/xjasonlyu/tun2socks/releases](https://github.com/xjasonlyu/tun2socks/releases)
```sh
fetch -o /tmp/tun2socks.zip https://github.com/xjasonlyu/tun2socks/releases/latest/download/tun2socks-freebsd-amd64.zip
cd /tmp && unzip tun2socks.zip
mkdir -p /usr/local/tun2socks
install -m 0755 /tmp/tun2socks-freebsd-amd64 /usr/local/tun2socks/tun2socks
```

> Имя файла tun2socks после распаковки может отличаться в зависимости от версии — проверь командой `ls /tmp/tun2socks*`.

---

## Лицензия

BSD 2-Clause — Copyright (c) 2026 Меркулов Павел Сергеевич

---

## Благодарности

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — Xray-core и протокол VLESS+Reality
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks) — tun2socks
- [OPNsense](https://opnsense.org) — открытая архитектура плагинов
