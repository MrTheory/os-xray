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

> Бинарники `xray-core` и `tun2socks` отсутствуют в репозитории OPNsense и устанавливаются вручную. Смотри раздел «Подготовка бинарников».

---

## Быстрый старт

### 1. Подготовка бинарников

#### xray-core

```sh
# Скачать последний релиз (пример для amd64)
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip xray.zip xray
install -m 0755 /tmp/xray /usr/local/bin/xray-core
xray-core version
```

#### tun2socks

```sh
# Скачать последний релиз (пример для amd64)
fetch -o /tmp/tun2socks.zip https://github.com/xjasonlyu/tun2socks/releases/latest/download/tun2socks-freebsd-amd64.zip
cd /tmp && unzip tun2socks.zip
mkdir -p /usr/local/tun2socks
install -m 0755 /tmp/tun2socks-freebsd-amd64 /usr/local/tun2socks/tun2socks
```

### 2. Установка плагина

```sh
# Скачать архив
fetch -o /tmp/os-xray.tar https://github.com/MrTheory/os-xray/releases/latest/download/os-xray.tar

# Распаковать и установить
cd /tmp && tar xf os-xray.tar && cd os-xray
sh install.sh
```

Скрипт автоматически:
- Проверит наличие бинарников xray-core и tun2socks
- Найдёт существующие конфиги `/usr/local/etc/xray-core/config.json` и `/usr/local/tun2socks/config.yaml` и импортирует их в OPNsense
- Скопирует файлы плагина
- Перезапустит configd
- Очистит кеши

### 3. Настройка в GUI

1. Обнови браузер **(Ctrl+F5)** → **VPN → Xray**
2. Вкладка **Instance** → кнопка **Import VLESS link** → вставь ссылку → **Parse & Fill**
3. Вкладка **General** → установи галку **Enable Xray**
4. Нажми **Apply**

### 4. Интерфейс и шлюз

| Шаг | Путь в GUI | Значение |
| --- | --- | --- |
| Назначить интерфейс | Interfaces → Assignments | + Add: `proxytun2socks0` |
| Включить и настроить | Interfaces → \<имя\> | Enable ✓, IPv4: Static, IP: `10.255.0.1/30` |
| Создать шлюз | System → Gateways → Add | Gateway IP: `10.255.0.1`, Far Gateway ✓, Monitoring off ✓ |

### 5. Селективная маршрутизация

- **Firewall → Aliases** — создай список IP/сетей/доменов для маршрутизации через VPN
- **Firewall → Rules → LAN** — добавь правило: Source = LAN net, Destination = alias, Gateway = PROXYTUN_GW

> MSS Clamping для Xray не требуется (в отличие от WireGuard).

---

## Удаление

```sh
cd /tmp/os-xray
sh install.sh uninstall
```

Скрипт остановит сервисы, удалит все файлы плагина, перезапустит configd.

---

## Устранение неполадок

**Меню VPN → Xray не появляется**
```sh
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5
```

**Сервис не запускается**
```sh
# Проверить статус
configctl xray status

# Проверить логи
cat /var/lib/php/tmp/PHP_errors.log | tail -30
```

**Проверить что xray-core реально работает**
```sh
/usr/local/opnsense/scripts/Xray/xray-service-control.php status
# Ожидаемый ответ:
# {"status":"ok","xray_core":"running","tun2socks":"running"}
```

**Конфиги генерируются при нажатии Apply в**
```
/usr/local/etc/xray-core/config.json
/usr/local/tun2socks/config.yaml
```

---

## Структура файлов

```
os-xray/
├── install.sh                                              ← установщик / деинсталлятор
└── plugin/
    ├── etc/inc/plugins.inc.d/
    │   └── xray.inc                                        ← регистрация сервиса в OPNsense
    ├── scripts/Xray/
    │   └── xray-service-control.php                        ← управление xray-core и tun2socks
    ├── service/conf/actions.d/
    │   └── actions_xray.conf                               ← configd: start/stop/restart/reconfigure/status
    └── mvc/app/
        ├── models/OPNsense/Xray/
        │   ├── General.xml / General.php                   ← модель: enable/disable
        │   ├── Instance.xml / Instance.php                 ← модель: параметры подключения
        │   └── Menu/Menu.xml                               ← пункт меню VPN → Xray
        ├── controllers/OPNsense/Xray/
        │   ├── IndexController.php                         ← рендеринг страницы
        │   ├── forms/general.xml                           ← форма General
        │   ├── forms/instance.xml                          ← форма Instance
        │   └── Api/
        │       ├── GeneralController.php                   ← API: enable/disable
        │       ├── InstanceController.php                  ← API: параметры подключения
        │       ├── ServiceController.php                   ← API: start/stop/reconfigure/status
        │       └── ImportController.php                    ← API: парсинг VLESS ссылки
        └── views/OPNsense/Xray/
            └── general.volt                                ← шаблон GUI
```

---

## Лицензия

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich (Меркулов Павел Сергеевич)

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.

---

## Автор

**Меркулов Павел Сергеевич**
Февраль 2026

## Благодарности

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — за разработку Xray-core и протокола VLESS+Reality
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks) — за tun2socks
- [OPNsense](https://opnsense.org) — за открытую архитектуру плагинов
