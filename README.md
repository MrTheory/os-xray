# os-xray

[![Release](https://img.shields.io/github/v/release/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/releases)
[![License](https://img.shields.io/github/license/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/blob/main/LICENSE)
[![Downloads](https://img.shields.io/github/downloads/MrTheory/os-xray/total)](https://github.com/MrTheory/os-xray/releases)
[![OPNsense](https://img.shields.io/badge/OPNsense-25.x%20%2F%2026.x-blue)](https://opnsense.org)
[![FreeBSD](https://img.shields.io/badge/FreeBSD-14.x%20amd64-red)](https://freebsd.org)

**Xray-core VPN plugin for OPNsense** — v2.0.0

Xray-core + tun2socks — нативный VPN-клиент для OPNsense с поддержкой селективной маршрутизации. VLESS+Reality через визард или произвольный config.json (любой протокол/транспорт). Обходит DPI-блокировки за счёт маскировки трафика под легитимный TLS.

---

## Возможности

- **Custom Config** — два режима: Wizard (VLESS+Reality через GUI-поля) или Custom (произвольный config.json для любого протокола и транспорта)
- **Импорт VLESS-ссылки** одной кнопкой — автоматически определяет wizard или custom режим; для xhttp, ws, grpc, h2, kcp генерирует полный config.json
- Полная поддержка параметров VLESS+Reality (UUID, flow, SNI, PublicKey, ShortID, Fingerprint)
- Управление туннелем через GUI: **VPN → Xray**
- При установке автоматически определяет и импортирует существующий конфиг xray-core и tun2socks
- Совместимость с селективной маршрутизацией OPNsense (Firewall Aliases + Rules + Gateway)
- Статус сервисов xray-core и tun2socks обновляется в GUI каждые 5 секунд
- **Кнопки Start / Stop / Restart** — управление сервисом прямо из GUI без перезагрузки страницы
- **Кнопка Validate Config** — сухой прогон конфига через `xray -test` без остановки сервиса
- **Кнопка Test Connection** — проверяет, что xray-core реально проксирует трафик
- **Вкладка Log** — Boot Log и Xray Core Log прямо в GUI
- **Вкладка Diagnostics** — статистика TUN-интерфейса: IP, MTU, байты, пакеты, uptime процессов, Ping RTT до VPN-сервера; автообновление каждые 30 секунд
- **Кнопка Copy Debug Info** — собирает diagnostics + логи в модалку для копирования в issue-репорт
- **Bypass Networks** — настраиваемый список CIDR-сетей для обхода VPN (direct routing)
- **Watchdog** — автоматический перезапуск при падении xray-core или tun2socks (настраивается)
- **Автозапуск после ребута** — интерфейс поднимается автоматически, нажимать Apply вручную не нужно
- ACL-права — доступ к GUI и API только для авторизованных пользователей с ролью `page-vpn-xray`

---

## Стек

```
xray-core (VLESS+Reality)
    ↓ SOCKS5 (по умолчанию 127.0.0.1:10808, настраивается)
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
| xray-core  | 24.x+ (рекомендуется)   |
| tun2socks  | Любая актуальная        |

---

## Установка

**Вариант 1 — через git clone (рекомендуется)**

```sh
cd /tmp
git clone https://github.com/MrTheory/os-xray.git
cd os-xray
sh install.sh
```

**Вариант 2 — через архив**

```sh
fetch -o /tmp/os-xray-v5.tar https://raw.githubusercontent.com/MrTheory/os-xray/refs/heads/main/os-xray-v5.tar
cd /tmp && tar xf os-xray-v5.tar && cd os-xray-v5
sh install.sh
```

Установщик автоматически:

- Покажет текущую и новую версию плагина и запросит подтверждение
- Проверит версию xray-core — если ниже 24.x, предложит автоматическое обновление
- Проверит наличие бинарников xray-core и tun2socks — если их нет, выведет ссылки для скачивания
- Проверит, не занят ли SOCKS5-порт (10808 по умолчанию) другим процессом
- Найдёт существующие конфиги и импортирует их в OPNsense (поля в GUI заполнятся сразу)
- Скопирует все файлы плагина, перезапустит configd, очистит кеши
- Установит boot-скрипт для автозапуска после ребута

Проверить установленную версию:
```sh
configctl xray version
```

---

## Настройка в GUI

Обнови браузер (`Ctrl+F5`) → **VPN → Xray**

1. Вкладка **Instance** → кнопка **Import VLESS link** → вставь ссылку → **Parse & Fill**
   - Для стандартных VLESS+Reality (TCP) ссылок → автоматически заполнит wizard-поля
   - Для ссылок с другим транспортом (xhttp, ws, grpc, h2, kcp) → автоматически сгенерирует Custom Config JSON
2. *(Опционально)* Поле **Bypass Networks** — укажи сети, которые должны идти в обход VPN (по умолчанию: частные сети 10/8, 172.16/12, 192.168/16)
3. *(Опционально)* **Config Mode** → Custom — для ручной вставки произвольного config.json (любой протокол/транспорт xray-core)
4. Вкладка **General** → установи галку **Enable Xray** (и **Enable Watchdog** по желанию)
5. Нажми **Apply**
5. Кнопка **Test Connection** — убедись, что туннель работает (показывает HTTP 200)
6. Кнопка **Validate Config** — проверить конфиг без перезапуска сервиса

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

## Outbound NAT (обязательно!)

Без этого трафик через туннель не будет NATиться и не уйдёт дальше VPN-сервера.

**Firewall → NAT → Outbound**

1. Переключи режим на **Hybrid outbound NAT rule generation** (если ещё не переключён)
2. Добавь правило **+**:

| Поле | Значение |
|------|----------|
| Interface | PROXYTUN (proxytun2socks0) |
| TCP/IP Version | IPv4 |
| Protocol | any |
| Source address | LAN net |
| Source port | any |
| Destination address | any |
| Destination port | any |
| Translation / target | Interface address |

> **Почему это нужно:** OPNsense по умолчанию NATит трафик только через WAN. Трафик, уходящий через TUN-интерфейс, не попадает под автоматические правила NAT. Без ручного правила пакеты уйдут с исходным LAN-адресом (например 192.168.1.x) и VPN-сервер их отбросит.

---

## Автозапуск после ребута

После ребута интерфейс `proxytun2socks0` поднимается автоматически — xray и tun2socks стартуют, интерфейс получает IP, firewall rules перезагружаются. Вручную нажимать Apply не нужно.

Работает через два механизма с защитой от двойного запуска (flock):
- **`xray_configure_do()`** — boot hook (приоритет 10), запускает процессы на раннем этапе загрузки
- **`/usr/local/etc/rc.syshook.d/start/50-xray`** — финальный скрипт, поднимает интерфейс и применяет routing/firewall когда OPNsense полностью загружен

Лог сохраняется в `/tmp/xray_syshook.log` (дозапись, ротация при превышении 50 KB).

---

## Watchdog

При включённом **Enable Watchdog** cron каждую минуту проверяет живость xray-core и tun2socks. При падении любого из процессов — оба перезапускаются автоматически. События пишутся в `/var/log/xray-watchdog.log` (ротация: 3 файла по 100 KB).

Watchdog не перезапускает сервис если он был остановлен вручную через кнопку **Stop** или **Apply** с отключённым Enable.

---

## Остановка сервиса

При остановке (`Stop` в GUI или `Apply` с отключённым Enable) плагин:
1. Останавливает tun2socks — он сам уничтожает TUN-интерфейс при завершении
2. Останавливает xray-core
3. Выставляет флаг намеренной остановки — watchdog не будет перезапускать сервис

---

## Удаление

```sh
cd /tmp/os-xray-v5
sh install.sh uninstall
```

---

## Устранение неполадок

### Диагностика — с чего начать

```sh
# 1. Версия плагина
configctl xray version

# 2. Статус сервисов
configctl xray status

# 3. Бинарники на месте?
ls -la /usr/local/bin/xray-core /usr/local/tun2socks/tun2socks

# 4. Логи
tail -30 /var/log/xray-core.log
cat /tmp/xray_syshook.log

# 5. TUN-интерфейс существует?
ifconfig proxytun2socks0

# 6. Конфиги
cat /usr/local/etc/xray-core/config.json
cat /usr/local/tun2socks/config.yaml
```

### Меню VPN → Xray не появляется

```sh
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5 в браузере
```

### Туннель не поднимается

```sh
# Запустить вручную и посмотреть вывод
/usr/local/opnsense/scripts/Xray/xray-service-control.php start
```

| Ошибка в логе | Причина | Решение |
|---|---|---|
| `xray-core not found` | Бинарник не установлен | Установи xray-core (см. раздел ниже) |
| `tun2socks not found` | Бинарник не установлен | Установи tun2socks (см. раздел ниже) |
| `Port XXXXX already in use` | SOCKS5 порт занят | Смени порт в GUI → Instance → SOCKS5 Port |
| `ERROR: config validation failed` | Невалидный конфиг xray-core | Проверь параметры VLESS (UUID, SNI, PublicKey) |
| `ERROR: xray-core exited immediately` | xray-core не стартует | Смотри `/var/log/xray-core.log` |
| `No config found in config.xml` | Не заполнены поля в GUI | Заполни Instance → Import VLESS link → Apply |
| `SKIP: lock file held` | Lock занят параллельным процессом | Подожди или удали `/var/run/xray_start.lock` |

### Сервис "stopped" в дашборде после перезагрузки

Xray запускается автоматически при загрузке через syshook и boot hook, если `General → Enable Xray` включён. Если не стартует:

```sh
# Проверь лог
cat /tmp/xray_syshook.log

# Проверь syshook скрипт
cat /usr/local/etc/rc.syshook.d/start/50-xray

# Запусти вручную
configctl xray start
```

### Сайты не открываются через VPN (туннель работает, curl OK, но браузер не грузит)

Не настроен Outbound NAT (см. раздел выше). Без NAT-правила на TUN-интерфейсе пакеты уходят с LAN-адресом и VPN-сервер их отбрасывает.

### Кнопка Apply / Start / Stop не работает ("No response from configd")

```sh
# 1. Перезапусти configd
service configd restart

# 2. Проверь lock-файл — зависший процесс?
ls -la /var/run/xray_start.lock
# Если файл старый (> 1 минуты):
rm -f /var/run/xray_start.lock
service configd restart

# 3. Посмотри лог
tail -20 /var/log/xray-core.log
```

### Проверить соединение через туннель

```sh
curl --socks5 127.0.0.1:10808 -s -o /dev/null -w "%{http_code}" https://1.1.1.1 --max-time 10
# 200 = OK, 000 = нет связи
```

### Проверить TUN-интерфейс

```sh
ifconfig proxytun2socks0
# Должен быть UP и иметь inet адрес (например 10.255.0.1)

# Статистика трафика через интерфейс
netstat -ibn | grep proxytun2socks0
```

### Watchdog не перезапускает сервис

```sh
# Флаг намеренной остановки — если существует, watchdog игнорирует
ls -la /var/run/xray_stopped.flag
# Удалить флаг чтобы watchdog начал перезапускать
rm -f /var/run/xray_stopped.flag

# Проверь что watchdog включён
# GUI → General → Enable Watchdog ✓

# Лог watchdog
tail -50 /var/log/xray-watchdog.log
```

### Где искать логи

| Лог | Путь | Содержит |
|---|---|---|
| Xray Core | `/var/log/xray-core.log` | Ошибки xray-core и tun2socks (Reality handshake, connection) |
| Boot | `/tmp/xray_syshook.log` | Автозапуск, назначение IP, reload firewall |
| Watchdog | `/var/log/xray-watchdog.log` | Перезапуски процессов |
| PHP ошибки | `/var/lib/php/tmp/PHP_errors.log` | Ошибки OPNsense PHP (GUI, API) |
| Система | `/var/log/system/latest.log` | Ошибки configd |

### Полезные команды

```sh
configctl xray version                # версия плагина
configctl xray status                 # статус сервисов (JSON)
configctl xray start                  # запустить
configctl xray stop                   # остановить
configctl xray restart                # перезапустить
configctl xray validate               # сухой прогон конфига
configctl xray testconnect            # проверка соединения
configctl xray ifstats                # статистика TUN-интерфейса
ps aux | grep -E 'xray|tun2socks'    # процессы
ifconfig proxytun2socks0              # TUN-интерфейс
netstat -rn | grep proxytun           # таблица маршрутизации
tail -f /var/log/xray-core.log        # мониторинг лога в реальном времени
```

### Проверить конфиги

```sh
# Конфиг xray-core (генерируется при Apply)
cat /usr/local/etc/xray-core/config.json

# Конфиг tun2socks
cat /usr/local/tun2socks/config.yaml

# Сухой прогон конфига без перезапуска сервиса
configctl xray validate
```

### Сброс и переустановка

```sh
# Полная переустановка без потери конфига OPNsense
sh install.sh uninstall
sh install.sh
```

---

## Структура файлов

```
os-xray/
├── install.sh
├── CHANGELOG.md
└── plugin/
    ├── +MANIFEST                               ← FreeBSD pkg метаданные
    ├── etc/
    │   ├── inc/plugins.inc.d/
    │   │   └── xray.inc                        ← регистрация сервиса, boot hook, cron watchdog
    │   ├── newsyslog.conf.d/
    │   │   └── xray.conf                       ← ротация xray-core.log и xray-watchdog.log
    │   └── rc.syshook.d/start/
    │       └── 50-xray                         ← автозапуск после ребута
    ├── scripts/Xray/
    │   ├── xray-service-control.php            ← управление xray-core и tun2socks
    │   ├── xray-watchdog.php                   ← watchdog: проверка и перезапуск процессов
    │   ├── xray-ifstats.php                    ← статистика TUN-интерфейса для Diagnostics
    │   └── xray-testconnect.php                ← проверка соединения через SOCKS5
    ├── service/conf/actions.d/
    │   └── actions_xray.conf                   ← configd actions
    └── mvc/app/
        ├── models/OPNsense/Xray/
        │   ├── General.xml / General.php       ← модель: enable, watchdog (v1.0.1)
        │   ├── Instance.xml / Instance.php     ← модель: параметры подключения (v1.0.5)
        │   ├── ACL/ACL.xml                     ← права доступа (page-vpn-xray)
        │   └── Menu/Menu.xml                   ← пункт меню VPN → Xray
        ├── controllers/OPNsense/Xray/
        │   ├── IndexController.php
        │   ├── forms/general.xml
        │   ├── forms/instance.xml
        │   └── Api/
        │       ├── GeneralController.php
        │       ├── InstanceController.php
        │       ├── ServiceController.php       ← start/stop/restart/status/version/log/validate/diagnostics/testconnect
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

Полная история изменений — в файле [CHANGELOG.md](CHANGELOG.md).

| Версия | Что изменилось |
|--------|---------------|
| 2.0.0  | Custom Config (wizard/custom), Import VLESS с авто-генерацией config.json для любого транспорта, нормализация xhttp↔splithttp, проверка версии xray-core при установке |
| 1.10.0 | Проверка версии при установке, `configctl xray version`, API version endpoint, Outbound NAT в README |
| 1.9.3  | Фиксы P1 (implode, socks5_port, validate tempfile, дедупликация), Bypass Networks, Copy Debug Info, Ping RTT, автообновление Diagnostics |
| 1.9.2  | Фикс fatal error tun2socks при stop, ротация watchdog лога |
| 1.9.1  | Хотфикс validate: синтаксис xray -test, расширение .json для tempnam |
| 1.9.0  | Улучшен hint для поля SOCKS5 Listen Address |
| 1.8.0  | Аудит безопасности: фиксы proc_kill, watchdog stopped flag, validate tempfile |
| 1.7.0  | Фикс блокировки GUI, фикс ifstats bytes, запуск 50-xray из do_start |
| 1.6.0  | BUG-5/9, Watchdog E1, Diagnostics E4, Validate Config E5 |
| 1.5.0  | Ротация лога, кнопки Start/Stop/Restart, вкладка Xray Core Log |
| 1.4.0  | Аудит безопасности P0-P2, xray-testconnect, flock, stderr в лог |
| 1.3.0  | Версионирование моделей, `+MANIFEST`, `Changelog.md` |
| 1.2.0  | Вкладка Log, кнопка Test Connection, интервал статуса 5 с |
| 1.1.0  | Надёжный install.sh: PHP-парсинг конфигов, check_port, ротация лога |
| 1.0.1  | Исправлен loglevel, TUN destroy при stop, flock |
| 1.0.0  | ACL, валидация UUID, санитизация ImportController |
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
Февраль — Март 2026

---

## Благодарности

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — Xray-core и протокол VLESS+Reality
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks) — tun2socks
- [OPNsense](https://opnsense.org) — открытая архитектура плагинов
- [yukh975](https://github.com/yukh975) - за помощь в тестировании
- [hohner36](https://github.com/hohner36) - за помощь в тестировании и настройке автоматизации
