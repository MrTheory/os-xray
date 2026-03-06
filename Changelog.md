# Changelog — os-xray

All notable changes to this project will be documented in this file.
Format: [Semantic Versioning](https://semver.org/).

---

## [1.10.0] — 2026-03-06

### Added
- **Version check in installer** — `install.sh` теперь показывает текущую и новую версию перед установкой; при повторной установке той же версии спрашивает подтверждение (Reinstall? [y/N]); при апгрейде — Upgrade? [Y/n]; при первой установке — Install? [Y/n]
- **`configctl xray version`** — команда для проверки версии плагина из терминала OPNsense; возвращает JSON `{"version":"1.10.0"}`
- **`GET /api/xray/service/version`** — API-эндпоинт для получения версии плагина из GUI или скриптов
- **`version.txt`** — файл версии записывается при установке в `/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt`; удаляется при uninstall вместе с директорией модели

### Changed
- `install.sh`: версия обновлена до 1.10.0
- `xray-service-control.php`: добавлена константа `XRAY_VERSION_FILE` и `case 'version'`
- `actions_xray.conf`: добавлен action `[version]`
- `ServiceController.php`: добавлен `versionAction()` (GET, read-only)

---

## [1.9.3] — 2026-03-05

### Fixed (Приоритет 1 — баги)
- **[P1-1]** `xray-service-control.php`: `lo0_alias_ensure()` — `implode('\n')` заменён на `implode("\n")`; одинарные кавычки давали literal `\n`, из-за чего `strpos()` не находил существующий alias и добавлял его повторно при каждом старте
- **[P1-2]** `xray-service-control.php`: `socks5_port` — добавлен `?: 10808` fallback; пустое поле в config.xml давало `(int)'' = 0`, xray-core слушал на порту 0
- **[P1-3]** `xray-service-control.php`: дублированная JSON-структура конфига (~40 строк) в `xray_write_config()` и `case 'validate'` вынесена в `xray_build_config_array(array $c): array`
- **[P1-4]** `xray-service-control.php`: `case 'validate'` — `tempnam()` создавал файл, а конкатенация `.json` — второй; оригинальный файл-сирота оставался в `/tmp`; добавлен `@unlink($tmpBase)` сразу после создания
- **[testconnect]** `xray-testconnect.php`: таймаут увеличен с 5с до 10с (при высоком RTT до VPN-сервера SOCKS5+TLS handshake не укладывался); адрес прокси теперь читается из `socks5_listen` конфига вместо хардкода `127.0.0.1`

### Added (Приоритет 2 — улучшения)
- **[P2-5/6] Bypass Networks** — новое поле `bypass_networks` в Instance.xml (v1.0.4): пользовательский CIDR-список сетей для direct routing (обход VPN); дефолт `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`; хардкод в `routing.rules` заменён на конфигурируемое значение; добавлена секция Routing в GUI-форме
- **[P2-7] Автообновление Diagnostics** — `setInterval(loadDiagnostics, 30000)` при активной вкладке Diagnostics; данные обновляются автоматически каждые 30 секунд
- **[P2-8] Copy Debug Info** — кнопка в панели Diagnostics; собирает JSON diagnostics + Boot Log + Core Log; показывает в модалке с автовыделением текста для копирования в issue-репорты
- **[P2-9] Ping RTT** — `xray-ifstats.php`: добавлен `ping -c 3 -W 2` до VPN-сервера; RTT отображается в Diagnostics; fallback "N/A" если ping заблокирован

### Tests
- Добавлен тест `writeConfigCustomBypassNetworks` — проверяет что кастомные CIDR-сети корректно попадают в routing rules
- Обновлены `sampleConfig()`, `MockConfigObject`, `getConfigReturnsAllFields` для поля `bypass_networks`

---

## [1.9.2] — 2026-03-04

### Fixed
- **`xray-service-control.php` — `do_stop()`**: убраны `usleep(300000)` и `tun_destroy()`. tun2socks сам уничтожает TUN-интерфейс при получении SIGTERM. Принудительный `ifconfig destroy` до завершения процесса вызывал `fatal error` в логе tun2socks: `"failed to destroy interface: device not configured"`.

### Added
- **`newsyslog.conf.d/xray.conf`**: добавлена ротация `/var/log/xray-watchdog.log` (644, 3 файла, 100 KB).

---

## [1.9.1] — 2026-03-01

### Хотфикс — Validate Config: пустой ответ от configd

#### БАГ — `xray_validate_config()`: неверный синтаксис команды
- **[hotfix]** `xray-service-control.php`: в `xray_validate_config()` команда `xray run -test -c` заменена на `xray -test -c` — субкоманда `run` не принимает флаг `-test`; при неверном синтаксисе xray-core 1.8.x писал ошибку в stderr минуя `exec()` capture, `$out` оставался пустым, GUI получал пустую строку и показывал «No response from configd»

#### БАГ — `case 'validate'`: tempnam без расширения `.json`
- **[hotfix]** `xray-service-control.php`: `tempnam('/tmp', 'xray-validate-')` заменён на `tempnam('/tmp', 'xray-validate-') . '.json'` — xray-core определяет формат конфига по расширению файла; файл без `.json` вызывал `Failed to get format` и немедленный выход с кодом 1 до любой валидации содержимого

---

## [1.9.0] — 2026-02-28

### Улучшение UX — Подсказка для поля SOCKS5 Listen Address

- **[UX]** `forms/instance.xml`: hint поля `socks5_listen` расширен — теперь явно указаны допустимые адреса: `127.0.0.1` (рекомендуется, loopback), `0.0.0.0` (все интерфейсы), любой IP реального интерфейса OPNsense; добавлено предупреждение что другие loopback-адреса (127.x.x.x) требуют ручного lo0 alias на FreeBSD и не рекомендуются

---

## [1.8.0] — 2026-02-28

### Итерация 10 — Аудит безопасности: критические фиксы (Баг 4, 5, 6)

#### БАГ-6 — validate action перезаписывал рабочий config.json
- **[БАГ-6]** `xray-service-control.php`: `case 'validate'` теперь генерирует конфиг во временный файл через `tempnam('/tmp', 'xray-validate-')` вместо записи в рабочий `XRAY_CONF`; временный файл удаляется в `finally {}`; права `chmod 0600`; запущенный сервис больше не затрагивается при валидации

#### БАГ-5 — Watchdog перезапускал намеренно остановленный сервис
- **[БАГ-5]** `xray-service-control.php`: добавлена константа `XRAY_STOPPED_FLAG = /var/run/xray_stopped.flag`
- **[БАГ-5]** `xray-service-control.php`: `do_stop()` создаёт флаг намеренной остановки после завершения; `do_start()` удаляет флаг перед запуском
- **[БАГ-5]** `xray-watchdog.php`: добавлена константа `XRAY_STOPPED_FLAG`; проверка флага до проверки процессов — если флаг существует, watchdog выходит без restart

#### БАГ-4 — proc_kill() убивал чужой процесс по переиспользованному PID
- **[БАГ-4]** `xray-service-control.php`: `proc_kill()` перед SIGTERM проверяет имя процесса через `ps -o comm= -p $pid`; если `comm` не содержит `xray` или `tun2socks` — PID-файл удаляется без отправки сигнала

---

## [1.7.0] — 2026-02-28

### Итерация 9 — Фикс daemon blocking, TUN IP после GUI, статистика ifstats

#### FIX — daemon -p блокировал GUI (Start/Stop/Restart зависали)
- **[daemon-block]** `xray-service-control.php`: `do_start()` после запуска процессов вызывает `exec('/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray &')` — `50-xray` уже содержит всю логику ожидания TUN и назначения IP; запуск в фоне (`&`) не блокирует GUI; syshook определяет что процессы уже запущены и пропускает их старт, выполняя только назначение IP

#### FIX — Bytes In/Out показывали 0 в панели Diagnostics
- **[ifstats-netstat]** `xray-ifstats.php`: парсер `netstat -ibn` переписан — вместо поиска первой строки по имени интерфейса теперь ищется строка с `<Link` в поле Network (единственная строка с реальной статистикой байт)
- **[ifstats-netstat]** `xray-ifstats.php`: исправлены индексы колонок под актуальный FreeBSD формат с полем `Idrop`: `Name Mtu Network Address Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll`; фиксированные индексы `[4],[6],[7],[9]` заменены на динамический поиск первого числового поля после Network — защита от разной длины поля Address
- **[ifstats-netstat]** Итоговые индексы относительно `$ipktsIdx`: `+0=Ipkts`, `+3=Ibytes`, `+4=Opkts`, `+6=Obytes`


---

## [1.6.0] — 2026-02-27

### Итерация 8 — BUG-5, BUG-9, E1, E4, E5

#### BUG-5 — logAction / xraylogAction: POST-only
- **[BUG-5]** `ServiceController.php`: `logAction()` и `xraylogAction()` теперь проверяют `isPost()` — логи содержат IP-адреса серверов, фрагменты ключей Reality и не должны быть доступны через GET/CSRF
- **[BUG-5]** `general.volt`: `loadLog()` переходит с `ajaxGet()` на `$.post()` для совместимости с POST-only эндпойнтами

#### BUG-9 — install.sh heredoc: set_include_path() перед require_once
- **[BUG-9]** `install.sh`: в PHP heredoc добавлен `set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path())` перед `require_once('config.inc')` — без этого при нестандартном CWD (например `/root`) PHP не находил `config.inc` и импорт конфига молча фалил

#### E1 — Watchdog: автоперезапуск при падении процессов
- **[E1]** `scripts/Xray/xray-watchdog.php`: новый скрипт; читает `watchdog_enabled` из config.xml; проверяет xray-core и tun2socks через PID-файлы; при падении хотя бы одного — вызывает restart обоих; записывает события в `/var/log/xray-watchdog.log`
- **[E1]** `actions_xray.conf`: добавлен `[watchdog]` action — запускает xray-watchdog.php
- **[E1]** `General.xml`: поле `watchdog_enabled` (BooleanField, default=0); версия модели поднята до 1.0.1
- **[E1]** `forms/general.xml`: чекбокс Enable Watchdog с подсказкой (частота проверок, путь к логу)
- **[E1]** `xray.inc`: добавлена `xray_cron()` — регистрирует cron-задачу `[watchdog]` через configd каждую минуту; `xray_cron_watchdog()` вызывает `Backend::configdRun('xray watchdog')`
- **[E1]** `install.sh`: установка/удаление xray-watchdog.php; версия `1.6.0`

#### E4 — Diagnostics: панель диагностики в GUI
- **[E4]** `scripts/Xray/xray-ifstats.php`: новый скрипт; читает TUN-интерфейс из config.xml; собирает через `ifconfig`/`netstat -ibn`: IP, MTU, bytes/pkts in/out, статус; uptime xray-core и tun2socks через `ps -o etime=`; выводит JSON
- **[E4]** `actions_xray.conf`: добавлен `[ifstats]` action
- **[E4]** `ServiceController.php`: добавлен `diagnosticsAction()` — GET, декодирует JSON из ifstats и отдаёт в GUI
- **[E4]** `general.volt`: добавлена вкладка Diagnostics — таблица со: TUN interface/status/IP, MTU, bytes in/out, packets in/out, uptime xray-core, uptime tun2socks; загружается лениво при переходе на вкладку через `shown.bs.tab`; кнопка Refresh; статус подсвечен лейблом (running/down)
- **[E4]** `install.sh`: установка/удаление xray-ifstats.php

#### E5 — Validate Config: сухой прогон из GUI
- **[E5]** `xray-service-control.php`: добавлен `case 'validate'` — генерирует config.json из config.xml и запускает `xray -test`; не трогает do_stop/do_start; выводит «OK: config is valid» или детальные ошибки xray-core
- **[E5]** `actions_xray.conf`: добавлен `[validate]` action
- **[E5]** `ServiceController.php`: добавлен `validateAction()` — POST-only (пишет config.json на диск); проверяет маркер `OK` в выводе configd
- **[E5]** `general.volt`: кнопка «Validate Config» рядом с Import VLESS в вкладке Instance; показывает «Validating...» во время запроса; зелёный · (валидный) или красный · (ошибка + текст ошибки)

#### Тесты
- Добавлен `tests/unit/Iter5Bug5Bug9E1E4E5Test.php` — 46 тестов покрывающих BUG-5, BUG-9, E1, E4, E5

---

## [1.6.0] — 2026-02-27

### Итерация 8 — BUG-5, BUG-9, E1, E4, E5 (фикс тестов)

#### Исправления регрессий в тестах
- **[voltStopActionHasConfirmation]** `general.volt`: сообщение подтверждения Stop вынесено в переменную `confirmStop` — убираем `)` из аргументов `serviceAction()` что ломало regex-тест
- **[voltLoadLogUsesPost]** `general.volt`: `$.post(apiEndpoint, {}` → `$.post(apiEndpoint, null` — убираем `}` из аргументов до вызова `$.post` что ломало `[^}]+` в regex-тесте
- **[installShSetIncludePathBeforeRequire]** `install.sh`: убрали `require_once('config.inc')` из текста комментария перед heredoc — тест `strpos` находил строку в комментарии раньше `set_include_path` в реальном PHP коде

---

## [1.5.0] — 2026-02-27

### Итерация 7 — BUG-11, E2, E3

#### BUG-11 — Ротация лога xray-core через newsyslog
- **[BUG-11]** `plugin/etc/newsyslog.conf.d/xray.conf`: добавлен конфиг ротации `/var/log/xray-core.log` — 600 KB, 3 архива, bzip2-сжатие, без сигнала (демон не держит fd открытым)
- **[BUG-11]** `install.sh`: шаг 3 теперь устанавливает `newsyslog.conf.d/xray.conf` в `/etc/newsyslog.conf.d/`; uninstall удаляет файл ротации. Версия установщика обновлена до `1.5.0`

#### E2 — Кнопки Start / Stop / Restart в GUI
- **[E2]** `ServiceController.php`: добавлены `startAction()`, `stopAction()`, `restartAction()` — POST-only, возвращают `result: ok/failed` с `message`; `startAction` и `restartAction` проверяют маркеры ERROR/failed в выводе configd; `stopAction` считает любой непустой ответ успехом
- **[E2]** `general.volt`: в панели статуса (вкладка Instance) добавлены кнопки Start / Stop / Restart; `serviceAction()` блокирует все три кнопки на время запроса и показывает спиннер; `updateStatus()` синхронизирует состояние кнопок с реальным статусом процессов; Stop запрашивает подтверждение через `confirm()`

#### E3 — Вкладка Xray Core Log в GUI
- **[E3]** `ServiceController.php`: добавлен `xraylogAction()` — `GET /api/xray/service/xraylog` → `tail -n 200 /var/log/xray-core.log` через configd; возвращает `['log' => ...]`
- **[E3]** `actions_xray.conf`: добавлен action `[xraylog]` — `tail -n 200 /var/log/xray-core.log`
- **[E3]** `general.volt`: вкладка Log разделена на две подвкладки — **Boot Log** (`xray_syshook.log`, 150 строк) и **Xray Core Log** (`xray-core.log`, 200 строк); логи загружаются лениво при переключении на подвкладку через `shown.bs.tab`; кнопки Refresh на каждой подвкладке; автоскролл вниз после загрузки; тёмная тема `<pre>` для читаемости

#### Тесты
- Добавлен `tests/unit/Iter4Bug11E2E3Test.php` — 34 теста покрывающих BUG-11, E2, E3

---

## [1.4.0] — 2026-02-27

### Итерация 6 — Аудит безопасности и исправление багов 

#### P0 — Критические
- **[BUG-1]** `xray.inc`: `goto`-логика управления lock-файлом заменена на `try/finally` — lock гарантированно освобождается при любом пути выполнения, включая исключения
- **[BUG-2]** `50-xray`: устранена shell-интерполяция `$TUN_IFACE` в PHP heredoc (потенциальный RCE) — значение передаётся через env-переменную `_TUN_IFACE` и читается через `getenv()`
- **[BUG-3]** `xray-service-control.php`: добавлена `xray_validate_config()` — `xray -test` вызывается перед запуском демона; при невалидном конфиге старт прерывается с явной ошибкой
- **[BUG-7]** `xray-service-control.php`, `xray.inc`: stderr/stdout демонов перенаправлены в `/var/log/xray-core.log` вместо `/dev/null`
- **[BUG-8]** `50-xray`: syshook ждёт освобождения lock-файла (до 10 с) перед продолжением — устранён race condition с `xray_configure_do()` при загрузке

#### P1 — Серьёзные
- **[BUG-4]** `actions_xray.conf`: хардкод `127.0.0.1:10808` в `[testconnect]` заменён вызовом нового скрипта `xray-testconnect.php`, который читает `socks5_port` из `config.xml`
- **[BUG-6]** `Instance.xml`: добавлена `<Mask>` валидация поля `tun_interface` — допустимы только имена вида `[a-z][a-z0-9]{0,13}[0-9]` (макс. 15 символов); версия модели поднята до `1.0.2`

#### P2 — Средние
- **[BUG-10]** `ImportController.php`: `parseVless()` теперь валидирует UUID regex-ом сразу при парсинге; используется якорь `\z` вместо `$` для защиты от trailing-newline инъекций
- **[BUG-12]** `ServiceController.php`: `reconfigureAction()` проверяет пустой ответ configd (timeout) и требует явный маркер `OK` в выводе для признания успеха

#### Добавлено
- `scripts/Xray/xray-testconnect.php`: новый скрипт для BUG-4; читает порт из конфига, валидирует диапазон, использует `escapeshellarg()`
- `install.sh`: установка и удаление `xray-testconnect.php` добавлены в шаги 3 и uninstall

#### Тесты
- Добавлено 8 тест-файлов, 110 тестов покрывающих все исправления итерации

---

## [1.3.0] — 2026-02-26

### Итерация 5 — Версионирование и упаковка
- **[I6]** `General.xml`: поднята версия модели до `1.0.0`
- **[I6]** `Instance.xml`: версия модели `1.0.1` (установлена в итерации 2)
- **pkg** `+MANIFEST`: добавлен стандартный FreeBSD-манифест пакета для распространения через `pkg`
- `Changelog.md`: добавлен этот файл

---

## [1.2.0] — 2026-02-26

### Итерация 4 — Улучшения UX и GUI
- **[I3]** Добавлена вкладка Log: `GET /api/xray/service/log` → `tail -n 150 /tmp/xray_syshook.log`
- **[I8]** Кнопка Test Connection: `POST /api/xray/service/testconnect` → curl через SOCKS5 → показывает HTTP-код
- **[I4]** Интервал обновления статуса уменьшен с 30 000 до 5 000 мс
- Добавлены `hint` для всех полей в `forms/instance.xml`

---

## [1.1.0] — 2026-02-26

### Итерация 3 — Надёжность и качество install.sh
- **[I5]** `detect_existing()`: переписан с grep/sed/awk на `php -r json_decode()` — надёжный парсинг config.json и config.yaml
- `install.sh`: добавлен `set -u`, явная инициализация всех `EXIST_*` переменных
- `install.sh`: добавлены хелперы `warn()` / `die()`
- `install.sh`: добавлена `check_port()` — проверка занятости SOCKS5-порта через `sockstat`
- **[B11]** `50-xray`: `exec > "$LOG"` заменён на `exec >> "$LOG"` (дозапись), добавлена ротация при превышении 50 KB
- `install.sh`: переменная `PLUGIN_VERSION="1.3.0"` в баннере установщика

---

## [1.0.1] — 2026-02-26

### Итерация 2 — Баги в логике управления сервисами
- **[B6]** `Instance.xml`: ключ loglevel `<e>` заменён на `<loglevel_error>` (корректный XML-тег); обратная совместимость с `"e"` оставлена в `xray-service-control.php`; версия модели поднята до `1.0.1`
- **[B9]** `do_stop()`: добавлена функция `tun_destroy()` — после остановки tun2socks вызывается `ifconfig <tun> destroy`, интерфейс удаляется из системы и OPNsense перестаёт считать шлюз живым
- **[B10]** `ServiceController::reconfigureAction()`: проверяет вывод configd, возвращает `result: failed` при ошибке вместо всегда `ok`
- **[B7]** `xray-service-control.php`: добавлены `lock_acquire()` / `lock_release()` с `flock(LOCK_EX|LOCK_NB)`, устраняют race condition при параллельном запуске из boot hook и Apply
- **[B7]** `xray.inc`: `xray_configure_do()` использует тот же lock-файл `/var/run/xray_start.lock` перед запуском процессов

---

## [1.0.0] — 2026-02-26

### Итерация 1 — Безопасность (критические фиксы)
- **[B1]** Создан `models/OPNsense/Xray/ACL/ACL.xml` с 5 привилегиями: `acl_xray_general`, `acl_xray_instance`, `acl_xray_service`, `acl_xray_import`, `acl_xray_ui`; все API-эндпоинты `/api/xray/*` теперь требуют роль `page-vpn-xray`
- **[B2]** `Instance.xml`: поле `uuid` — добавлен `<Mask>` с regex UUID v4
- **[B3]** `Instance.xml`: поле `server_address` — добавлен `<Mask>` для hostname/IPv4, запрещены спецсимволы
- **[B5]** `ImportController.php`: все поля из `parseVless()` проходят через `htmlspecialchars()`; `flow` и `fp` — whitelist-проверка; `#name` экранируется при парсинге

---

## [0.9.0] — первоначальный релиз

- Базовая интеграция xray-core (VLESS+Reality) + tun2socks в OPNsense MVC
- Import VLESS-ссылок через `ImportController`
- Автозапуск через `rc.syshook.d/start/50-xray` и `plugins.inc.d/xray.inc`
- Генерация `config.json` и `config.yaml` при каждом Apply
- Статус xray-core и tun2socks в GUI
