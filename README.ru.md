[English](README.md) | **Русский**

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
6. Кнопка **Test Connection** — убедись, что туннель работает (показывает HTTP 200)
7. Кнопка **Validate Config** — проверить конфиг без перезапуска сервиса

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

### Пошаговая диагностика

Выполняй команды по порядку. Каждый шаг сужает проблему:

```sh
# Шаг 1 — Плагин и бинарники
configctl xray version
ls -la /usr/local/bin/xray-core /usr/local/tun2socks/tun2socks
/usr/local/bin/xray-core version          # версия xray-core (должна быть 24.x+)

# Шаг 2 — Статус сервисов
configctl xray status                      # JSON: статус xray_core + tun2socks
ps aux | grep -E 'xray|tun2socks'         # реальные процессы

# Шаг 3 — Валидация конфига
configctl xray validate                    # сухой прогон без перезапуска
# или напрямую:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config.json

# Шаг 4 — Сеть
ifconfig proxytun2socks0                   # TUN-интерфейс: UP + inet адрес?
netstat -rn | grep proxytun               # запись в таблице маршрутизации?
curl --socks5 127.0.0.1:10808 -s -o /dev/null -w "%{http_code}" https://1.1.1.1 --max-time 10
# 200 = туннель работает, 000 = нет связи

# Шаг 5 — Файрвол
pfctl -sr | grep proxytun                 # правила файрвола для TUN
pfctl -sn | grep proxytun                 # NAT-правила для TUN

# Шаг 6 — Логи (последние ошибки)
tail -30 /var/log/xray-core.log
cat /tmp/xray_syshook.log
tail -20 /var/log/xray-watchdog.log

# Шаг 7 — Конфиги
cat /usr/local/etc/xray-core/config.json
cat /usr/local/tun2socks/config.yaml
```

---

### Справочник ошибок — все сообщения плагина

#### Ошибки запуска/остановки

| Сообщение | Причина | Решение |
|---|---|---|
| `xray-core not found at /usr/local/bin/xray-core` | Бинарник не установлен | Установить xray-core (см. раздел ниже) |
| `tun2socks not found at /usr/local/tun2socks/tun2socks` | Бинарник не установлен | Установить tun2socks (см. ниже) |
| `Xray is disabled in config` | Не включён Enable | GUI → General → Enable Xray ✓ → Apply |
| `Another start is already in progress (lock held)` | Параллельный запуск, lock занят | Подождать 30с. Если не проходит: `rm -f /var/run/xray_start.lock` |
| `ERROR: Failed to start Xray services` | xray-core или tun2socks упал при старте | Смотри `/var/log/xray-core.log` |
| `No config found in config.xml` | Поля GUI пустые | Импортировать VLESS-ссылку или заполнить поля → Apply |

#### Ошибки валидации конфига

| Сообщение | Причина | Решение |
|---|---|---|
| `ERROR: config validation failed` + вывод xray | Невалидный конфиг xray-core | Смотри вывод xray ниже ошибки |
| `ERROR: custom_config is empty` | Custom-режим, но textarea пустое | Вставить config.json в поле Custom Config |
| `ERROR: custom_config is not valid JSON` | Некорректный JSON в custom config | Проверить JSON (запятые, скобки, кавычки) |
| `ERROR: Cannot create temp file for validation` | /tmp заполнен или проблема прав | `df /tmp` — проверить место на диске |
| `ERROR: config file not found after write` | Запись не удалась | Проверить права `/usr/local/etc/xray-core/` |

#### Ошибки валидации xray-core (из `xray -test`)

| Вывод xray-core | Причина | Решение |
|---|---|---|
| `invalid user id` | Некорректный UUID | Проверить формат: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` |
| `unknown transport protocol: xhttp` | xray-core < 24.x не знает xhttp | Обновить xray-core до 24.x+ или плагин автоматически нормализует в splithttp |
| `REALITY only supports TCP, H2, gRPC and DomainSocket` | Неподдерживаемая комбинация транспорт+security | Использовать Custom Config для non-TCP Reality или сменить транспорт/security |
| `failed to dial` | VPN-сервер недоступен | Проверить адрес и порт: `ping` или `nc -zv host port` |
| `address already in use` | SOCKS5-порт занят | `sockstat -4l \| grep 10808` — сменить порт в GUI или остановить конфликтующий процесс |

#### Сетевые предупреждения

| Предупреждение | Причина | Влияние |
|---|---|---|
| `WARNING: Cannot read lo0 interface` | Проблема интерфейса lo0 (очень редко) | SOCKS5 на 127.0.0.1 может не работать |
| `WARNING: Failed to add lo0 alias` | Alias уже существует или проблема прав | Обычно безвредно — alias уже настроен |

---

### Меню VPN → Xray не появляется

```sh
# Очистить кеш меню OPNsense
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5 в браузере

# Если не помогло — проверить что файлы плагина на месте:
ls /usr/local/opnsense/mvc/app/models/OPNsense/Xray/Menu/Menu.xml
ls /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/IndexController.php
```

---

### Туннель не поднимается

```sh
# Запустить вручную для полного вывода:
/usr/local/opnsense/scripts/Xray/xray-service-control.php start

# Если нет вывода — проверить включён ли сервис:
configctl xray status

# Если "disabled in config":
# GUI → General → Enable Xray ✓ → Apply

# Валидация конфига отдельно:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config.json
```

---

### Сервис "stopped" после перезагрузки

Xray стартует автоматически при загрузке, если `General → Enable Xray` включён. Если не стартует:

```sh
# 1. Проверить лог загрузки
cat /tmp/xray_syshook.log

# 2. Проверить скрипты загрузки
ls -la /usr/local/etc/rc.syshook.d/start/50-xray
ls -la /usr/local/etc/inc/plugins.inc.d/xray.inc

# 3. Проверить флаг намеренной остановки
ls -la /var/run/xray_stopped.flag
# Если есть — удалить:
rm -f /var/run/xray_stopped.flag

# 4. Запустить вручную
configctl xray start
```

---

### Кнопка Apply / Start / Stop не работает ("No response from configd")

```sh
# 1. Проверить что configd запущен
service configd status

# 2. Если не запущен
service configd start

# 3. Проверить lock-файл
ls -la /var/run/xray_start.lock
# Если старше 1 минуты:
rm -f /var/run/xray_start.lock

# 4. Проверить зависший PHP-процесс
ps aux | grep xray-service-control
# Если старше 2 минут:
kill <PID>

# 5. Перезапустить configd
service configd restart
# Подождать 3 секунды, затем попробовать в GUI

# 6. Проверить лог configd
tail -20 /var/log/system/latest.log
```

---

### Сайты не открываются через VPN

**Симптом:** `curl --socks5` возвращает 200, но браузер/LAN-клиенты не могут открыть сайты через VPN.

**Причина:** Нет Outbound NAT правила для TUN-интерфейса.

```sh
# Проверить NAT-правила для proxytun:
pfctl -sn | grep proxytun

# Если пусто — добавить Outbound NAT правило (см. раздел выше)
```

**Другие причины:**
```sh
# DNS не резолвит через туннель?
# Попробуй напрямую по IP:
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10

# Если IP работает, а домен нет — проблема DNS
# Проверить настройки DNS: GUI → System → General → DNS

# MTU слишком большой? Попробуй уменьшить:
# GUI → Instance → MTU → 1400 (по умолчанию 1500)
```

---

### Проблемы TUN-интерфейса

```sh
# Интерфейс не существует
ifconfig proxytun2socks0
# "does not exist" → tun2socks не запущен или упал

# Проверить процесс tun2socks
ps aux | grep tun2socks
# Если не запущен:
configctl xray start

# Интерфейс есть, но нет IP
ifconfig proxytun2socks0
# Если нет строки "inet" → syshook не назначил IP
# Назначить вручную:
ifconfig proxytun2socks0 10.255.0.1/30 up

# Интерфейс UP, но нет трафика (Ibytes/Obytes = 0)
netstat -ibn | grep proxytun2socks0
# Проверить маршрутизацию:
netstat -rn | grep proxytun
# Если нет записи → шлюз не настроен или нет правил файрвола

# Интерфейс исчезает после ребута
# GUI → Interfaces → <TUN-интерфейс> → Prevent interface removal ✓
# Обязательно! Без этого OPNsense удаляет интерфейс при загрузке
```

---

### Проблемы Custom Config

```sh
# Конфиг не применяется в custom-режиме
# 1. Проверить что config_mode = custom:
cat /usr/local/etc/xray-core/config.json | head -5

# 2. Проверить синтаксис JSON:
echo '<ваш json>' | python3 -m json.tool
# или:
configctl xray validate

# 3. Частые ошибки JSON:
# - Запятая после последнего элемента массива/объекта
# - Одинарные кавычки вместо двойных
# - Отсутствие кавычек у ключей
# - Комментарии (// или /* */) — не допускаются в JSON

# 4. Транспорт xhttp на xray-core < 24.x:
/usr/local/bin/xray-core version
# Если версия 1.x — плагин автоматически заменяет xhttp→splithttp
# Если всё равно не работает — обновить xray-core до 24.x+
```

---

### Проблемы с импортом VLESS-ссылки

```sh
# Кнопка Import не работает / возвращает ошибку
# 1. Проверить консоль браузера (F12 → Console) на JS-ошибки

# 2. Ссылка должна начинаться с "vless://"
# Другие протоколы (vmess://, ss://) не поддерживаются для импорта
# Используй Custom Config и вставь config.json вручную

# 3. Формат ссылки:
# vless://UUID@HOST:PORT?params#name
# Обязательно: UUID, HOST, PORT
# Опционально: type, security, sni, fp, pbk, sid, flow, path, host и т.д.

# 4. Порт 1-65535, HOST не пустой, UUID не пустой

# 5. После импорта нажми Apply для запуска сервиса!
```

---

### Проблемы подключения VLESS+Reality

```sh
# "failed to dial" в xray-core.log
# 1. Сервер доступен?
nc -zv <server_host> <server_port>
# или:
ping <server_host>

# 2. Неправильный порт?
# Проверить: GUI → Instance → Server Port

# 3. Ошибка Reality handshake в логе:
tail -50 /var/log/xray-core.log | grep -i reality
# Частые причины:
# - Неправильный PublicKey (pbk) — должен соответствовать приватному ключу сервера
# - Неправильный ShortID (sid) — должен совпадать с конфигом сервера
# - Неправильный SNI — должен быть в списке разрешённых на сервере
# - Неправильный fingerprint — попробуй "chrome" (самый совместимый)
# - Расхождение времени — Reality чувствителен к синхронизации
#   ntpdate pool.ntp.org    # синхронизировать время
#   date                     # проверить текущее время

# 4. Сервер блокирует ваш IP?
# Попробуй с другой сети или проверь логи сервера
```

---

### Проблемы Watchdog

```sh
# Watchdog не перезапускает после падения
# 1. Watchdog включён?
# GUI → General → Enable Watchdog ✓

# 2. Флаг намеренной остановки?
ls -la /var/run/xray_stopped.flag
# Если есть — watchdog намеренно игнорирует. Удалить:
rm -f /var/run/xray_stopped.flag

# 3. Cron запускает watchdog?
crontab -l | grep xray
# Должна быть запись xray-watchdog.php

# 4. Лог watchdog
tail -30 /var/log/xray-watchdog.log
# "restart FAILED" → смотри xray-core.log для причины

# Watchdog в цикле перезапусков
# Причина: xray-core или tun2socks падает сразу после старта
# Сначала исправь причину:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config.json
# Исправь конфиг — после этого watchdog заработает
```

---

### Проблемы версии xray-core

```sh
# Проверить установленную версию
/usr/local/bin/xray-core version

# Проблемы версии 1.x:
# - Транспорт "xhttp" → не поддерживается, используй "splithttp" (плагин нормализует автоматически)
# - REALITY + splithttp → НЕ поддерживается вообще в 1.x
# - Отсутствуют некоторые фичи безопасности

# Обновить до последней версии:
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip -o xray.zip xray
# Сначала остановить сервис:
configctl xray stop
install -m 0755 /tmp/xray /usr/local/bin/xray-core
configctl xray start
/usr/local/bin/xray-core version    # проверить
```

---

### Проблемы производительности

```sh
# Высокая задержка через туннель
# 1. Проверить RTT до VPN-сервера
ping -c 5 <server_host>
# GUI → вкладка Diagnostics показывает Ping RTT

# 2. Туннель перегружен?
netstat -ibn | grep proxytun2socks0
# Сравнить Ibytes/Obytes во времени

# 3. Попробовать уменьшить MTU
# GUI → Instance → MTU → 1400 (или 1300 для двойной инкапсуляции)

# 4. Проверить нагрузку сервера — сам VPN-сервер может тормозить

# Использование CPU/памяти
ps aux | grep -E 'xray|tun2socks' | awk '{print $3, $4, $11}'
# Столбец 1 = %CPU, 2 = %MEM, 3 = процесс
# xray-core обычно <5% CPU, ~30MB RAM
# tun2socks обычно <3% CPU, ~15MB RAM
```

---

### Проблемы прав и файлов

```sh
# Файлы конфигов не доступны для записи
ls -la /usr/local/etc/xray-core/
ls -la /usr/local/tun2socks/
# config.json: -rw-r----- (0640)
# Директории: drwxr-x--- (0750)

# Исправить права:
chmod 0640 /usr/local/etc/xray-core/config.json
chmod 0750 /usr/local/etc/xray-core/

# Бинарник не исполняемый
chmod 0755 /usr/local/bin/xray-core
chmod 0755 /usr/local/tun2socks/tun2socks

# PHP-ошибки (GUI не грузится, API возвращает 500)
tail -30 /var/lib/php/tmp/PHP_errors.log

# configd не может выполнить скрипты
ls -la /usr/local/opnsense/scripts/Xray/
# Все .php файлы должны быть исполняемыми (-rwxr-xr-x)
chmod 0755 /usr/local/opnsense/scripts/Xray/*.php
service configd restart
```

---

### Зомби-процессы

```sh
# Несколько процессов xray-core или tun2socks
ps aux | grep -E 'xray-core|tun2socks' | grep -v grep

# Если больше одного — убить все и перезапустить:
pkill -f xray-core
pkill -f tun2socks
rm -f /var/run/xray_core.pid /var/run/tun2socks.pid /var/run/xray_start.lock
sleep 1
configctl xray start

# PID-файл есть, но процесс мёртв
cat /var/run/xray_core.pid
ps -p <PID>
# Если "No such process" — устаревший PID-файл
rm -f /var/run/xray_core.pid /var/run/tun2socks.pid
configctl xray start
```

---

### Проблемы DNS через VPN

```sh
# Симптом: IP работают, домены не резолвятся

# 1. Тест DNS через туннель
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10    # IP — должен работать
curl --socks5 127.0.0.1:10808 -s https://google.com --max-time 10  # домен — не работает?

# 2. DNS может утекать мимо туннеля
# Проверить DNS-серверы:
cat /etc/resolv.conf

# 3. Направить DNS через туннель:
# GUI → System → General → DNS Servers
# Добавить DNS-сервер (например 1.1.1.1) с Gateway = PROXYTUN_GW

# 4. Альтернатива — встроенный DNS xray-core:
# В Custom Config режиме добавить секцию "dns" в config.json:
# {"dns": {"servers": ["1.1.1.1", "8.8.8.8"]}}
```

---

### Селективная маршрутизация не работает

```sh
# Трафик идёт напрямую вместо VPN

# 1. Правила файрвола существуют?
pfctl -sr | grep PROXYTUN
# Должны быть правила с gateway = PROXYTUN_GW

# 2. Алиас заполнен?
pfctl -t <alias_name> -T show
# Должен содержать IP/сети

# 3. Шлюз доступен?
netstat -rn | grep proxytun
# Должен показать gateway 10.255.0.2 через proxytun2socks0

# 4. Шлюз помечен как down?
# GUI → System → Gateways → Status
# Если "down" — см. раздел Мониторинг шлюза

# 5. Порядок правил важен! VPN-правило должно быть ВЫШЕ default allow
# GUI → Firewall → Rules → LAN
# Перетащить VPN-правило выше "Default allow LAN"
```

---

### Проблемы мониторинга шлюза

```sh
# Шлюз показывает "down" хотя туннель работает

# dpinger (монитор шлюзов OPNsense) использует ICMP
# xray-core не отвечает на ICMP → dpinger видит шлюз как "down"

# Решение 1: Отключить мониторинг для этого шлюза
# GUI → System → Gateways → Edit PROXYTUN_GW
# Disable Gateway Monitoring ✓

# Решение 2: Указать IP для мониторинга
# GUI → System → Gateways → Edit PROXYTUN_GW
# Monitor IP: 1.1.1.1 (или любой IP, отвечающий на ping через VPN)
# Примечание: может не работать если xray-core отбрасывает ICMP
```

---

### Расположение логов

| Лог | Путь | Содержит | Ротация |
|---|---|---|---|
| Xray Core | `/var/log/xray-core.log` | stderr xray-core и tun2socks | 600 KB, 3 файла |
| Boot | `/tmp/xray_syshook.log` | Автозапуск, IP, firewall reload | 50 KB, в скрипте |
| Watchdog | `/var/log/xray-watchdog.log` | Обнаружение падений, перезапуски | 100 KB, 3 файла |
| PHP ошибки | `/var/lib/php/tmp/PHP_errors.log` | Ошибки OPNsense PHP (GUI, API) | Управляется OPNsense |
| Система | `/var/log/system/latest.log` | Ошибки configd | Управляется OPNsense |

---

### Все команды плагина

```sh
# Управление сервисом
configctl xray start                  # запустить xray-core + tun2socks
configctl xray stop                   # остановить оба сервиса
configctl xray restart                # остановить + запустить
configctl xray reconfigure            # вызывается кнопкой Apply (stop + start)

# Диагностика
configctl xray status                 # JSON: статус сервисов
configctl xray version                # JSON: версия плагина
configctl xray validate               # сухой прогон валидации конфига
configctl xray testconnect            # curl-тест через SOCKS5 прокси
configctl xray ifstats                # JSON: статистика TUN, uptime, RTT

# Логи
configctl xray log                    # последние 150 строк boot-лога
configctl xray xraylog                # последние 200 строк лога xray-core

# Прямой запуск скриптов (для отладки)
/usr/local/opnsense/scripts/Xray/xray-service-control.php start
/usr/local/opnsense/scripts/Xray/xray-service-control.php status
/usr/local/opnsense/scripts/Xray/xray-service-control.php validate
/usr/local/opnsense/scripts/Xray/xray-ifstats.php
/usr/local/opnsense/scripts/Xray/xray-testconnect.php

# Системные
ps aux | grep -E 'xray|tun2socks'    # запущенные процессы
ifconfig proxytun2socks0              # TUN-интерфейс
netstat -rn | grep proxytun           # таблица маршрутизации
netstat -ibn | grep proxytun          # статистика трафика интерфейса
sockstat -4l | grep 10808             # кто использует SOCKS5-порт
tail -f /var/log/xray-core.log        # мониторинг лога в реальном времени
```

---

### Сброс и переустановка

```sh
# Полная переустановка без потери конфига OPNsense
sh install.sh uninstall
sh install.sh

# Ядерный вариант — очистить всё и начать с нуля
configctl xray stop
rm -f /var/run/xray_*.pid /var/run/xray_*.lock /var/run/xray_*.flag
rm -f /usr/local/etc/xray-core/config.json
rm -f /usr/local/tun2socks/config.yaml
sh install.sh uninstall
sh install.sh
# Затем заново импортировать VLESS-ссылку и Apply
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
