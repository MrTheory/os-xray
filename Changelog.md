# Changelog — os-xray

All notable changes to this project will be documented in this file.
Format: [Semantic Versioning](https://semver.org/).

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
