# os-xray v1.3.0

**Xray-core (VLESS+Reality) VPN plugin for OPNsense**

Xray-core с протоколом VLESS+Reality + tun2socks — нативный VPN-клиент для OPNsense с поддержкой селективной маршрутизации. Обходит DPI-блокировки за счёт маскировки трафика под легитимный TLS.

---

## Возможности

- Импорт VLESS-ссылки одной кнопкой — **Import VLESS link → Parse & Fill**
- Полная поддержка параметров VLESS+Reality (UUID, flow, SNI, PublicKey, ShortID, Fingerprint)
- Управление туннелем через GUI: **VPN → Xray**
- При установке автоматически определяет и импортирует существующий конфиг xray-core и tun2socks
- Совместимость с селективной маршрутизацией OPNsense (Firewall Aliases + Rules + Gateway)
- Статус сервисов xray-core и tun2socks обновляется в GUI каждые 5 секунд
- **Кнопка Test Connection** — проверяет, что xray-core реально проксирует трафик
- **Вкладка Log** — просмотр последних 150 строк boot-лога прямо в GUI
- **Автозапуск после ребута** — интерфейс поднимается автоматически, нажимать Apply вручную не нужно
- ACL-права — доступ к GUI и API только для авторизованных пользователей с ролью `page-vpn-xray`

---

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

---

## Системные требования

| Компонент  | Версия                  |
|------------|-------------------------|
| OPNsense   | 25.x / 26.x             |
| FreeBSD    | 14.x amd64              |
| xray-core  | Любая актуальная        |
| tun2socks  | Любая актуальная        |

---

## Установка

```sh
fetch -o /tmp/os-xray-v3.tar https://raw.githubusercontent.com/MrTheory/os-xray/refs/heads/main/os-xray-v3.tar
cd /tmp && tar xf os-xray-v4.tar
sh install.sh
```

Установщик автоматически:

- Проверит наличие бинарников xray-core и tun2socks — если их нет, выведет ссылки для скачивания
- Проверит, не занят ли SOCKS5-порт (10808 по умолчанию) другим процессом
- Найдёт существующие конфиги и импортирует их в OPNsense (поля в GUI заполнятся сразу)
- Скопирует все файлы плагина, перезапустит configd, очистит кеши
- Установит boot-скрипт для автозапуска после ребута

---

## Настройка в GUI

Обнови браузер (`Ctrl+F5`) → **VPN → Xray**

1. Вкладка **Instance** → кнопка **Import VLESS link** → вставь ссылку → **Parse & Fill**
2. Вкладка **General** → установи галку **Enable Xray**
3. Нажми **Apply**
4. Кнопка **Test Connection** — убедись, что туннель работает (показывает HTTP 200)

---

## Интерфейс и шлюз

| Шаг | Путь в GUI | Значение |
|-----|-----------|----------|
| Назначить интерфейс | Interfaces → Assignments | + Add: proxytun2socks0 |
| Включить и настроить | Interfaces → \<имя\> | Enable ✓, IPv4: Static, IP: `10.255.0.1/30` |
| **Предотвратить удаление** | Interfaces → \<имя\> | **Prevent interface removal ✓** |
| Создать шлюз | System → Gateways → Add | Gateway IP: `10.255.0.2`, Far Gateway ✓, Monitoring off ✓ |

> **Важно:** галочка **Prevent interface removal** обязательна — без неё OPNsense может удалить интерфейс из конфига при ребуте, если tun2socks ещё не успел его создать.

---

## Селективная маршрутизация

- **Firewall → Aliases** — создай список IP/сетей/доменов для маршрутизации через VPN
- **Firewall → Rules → LAN** — добавь правило: Source = LAN net, Destination = alias, Gateway = PROXYTUN_GW

MSS Clamping для Xray не требуется (в отличие от WireGuard).

---

## Автозапуск после ребута

После ребута интерфейс `proxytun2socks0` поднимается автоматически — xray и tun2socks стартуют, интерфейс получает IP, firewall rules перезагружаются. Вручную нажимать Apply не нужно.

Работает через два механизма с защитой от двойного запуска (flock):
- **`xray_configure_do()`** — boot hook (приоритет 10), запускает процессы на раннем этапе загрузки
- **`/usr/local/etc/rc.syshook.d/start/50-xray`** — финальный скрипт, поднимает интерфейс и применяет routing/firewall когда OPNsense полностью загружен

Лог сохраняется в `/tmp/xray_syshook.log` (дозапись, ротация при превышении 50 KB). Просмотреть прямо в GUI: вкладка **Log** → кнопка **Refresh**.

---

## Остановка сервиса

При остановке (`Stop` в GUI или `Apply` с отключённым Enable) плагин:
1. Останавливает tun2socks
2. **Удаляет TUN-интерфейс** (`ifconfig proxytun2socks0 destroy`) — OPNsense перестаёт считать шлюз живым
3. Останавливает xray-core

Это предотвращает ситуацию, когда трафик уходит в никуда после остановки VPN.

---

## Удаление

```sh
cd /tmp/os-xray-v3
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

**Проверить соединение через туннель**
```sh
curl --socks5 127.0.0.1:10808 -s -o /dev/null -w "%{http_code}" https://1.1.1.1 --max-time 5
# 200 = OK
```

**Просмотреть лог boot-скрипта**
```sh
cat /tmp/xray_syshook.log
```

**Просмотреть лог ошибок PHP**
```sh
tail -30 /var/lib/php/tmp/PHP_errors.log
```

Конфиги генерируются при нажатии Apply:
```
/usr/local/etc/xray-core/config.json
/usr/local/tun2socks/config.yaml
```

---

## Структура файлов

```
os-xray/
├── install.sh
├── Changelog.md
└── plugin/
    ├── +MANIFEST                               ← FreeBSD pkg метаданные
    ├── etc/
    │   ├── inc/plugins.inc.d/
    │   │   └── xray.inc                        ← регистрация сервиса, boot hook
    │   └── rc.syshook.d/start/
    │       └── 50-xray                         ← автозапуск после ребута
    ├── scripts/Xray/
    │   └── xray-service-control.php            ← управление xray-core и tun2socks
    ├── service/conf/actions.d/
    │   └── actions_xray.conf                   ← configd actions
    └── mvc/app/
        ├── models/OPNsense/Xray/
        │   ├── General.xml / General.php       ← модель: enable/disable (v1.0.0)
        │   ├── Instance.xml / Instance.php     ← модель: параметры подключения (v1.0.1)
        │   ├── ACL/ACL.xml                     ← права доступа (page-vpn-xray)
        │   └── Menu/Menu.xml                   ← пункт меню VPN → Xray
        ├── controllers/OPNsense/Xray/
        │   ├── IndexController.php
        │   ├── forms/general.xml
        │   ├── forms/instance.xml
        │   └── Api/
        │       ├── GeneralController.php
        │       ├── InstanceController.php
        │       ├── ServiceController.php       ← start/stop/reconfigure/status/log/testconnect
        │       └── ImportController.php        ← парсинг VLESS-ссылки
        └── views/OPNsense/Xray/
            └── general.volt
```

---

## Если бинарники ещё не установлены

Установщик сам сообщит об отсутствии бинарников. Ниже — команды для ручной установки.

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

## Changelog

Полная история изменений — в файле [Changelog.md](Changelog.md).

| Версия | Что изменилось |
|--------|---------------|
| 1.3.0  | Версионирование моделей, `+MANIFEST`, `Changelog.md` |
| 1.2.0  | Вкладка Log, кнопка Test Connection, интервал статуса 5 с, hints для полей |
| 1.1.0  | Надёжный install.sh: PHP-парсинг конфигов, `set -u`, check_port, ротация лога |
| 1.0.1  | Исправлен loglevel, TUN destroy при stop, реальный статус reconfigure, flock |
| 1.0.0  | ACL, валидация UUID и server_address, санитизация ImportController |
| 0.9.0  | Первоначальный релиз |

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

Меркулов Павел Сергеевич  
Февраль 2026

---

## Благодарности

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — Xray-core и протокол VLESS+Reality
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks) — tun2socks
- [OPNsense](https://opnsense.org) — открытая архитектура плагинов
