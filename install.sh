#!/bin/sh
# os-xray OPNsense Plugin Installer
# Xray-core (VLESS+Reality) + tun2socks
# Tested on OPNsense 25.x / FreeBSD 14.x
# Author: Меркулов Павел Сергеевич
#
# Usage:
#   sh install.sh            — install
#   sh install.sh uninstall  — remove

# Итерация 3:
#   - set -u: защита от неинициализированных переменных
#   - detect_existing(): PHP-парсинг вместо grep/sed/awk
#   - check_port(): проверка занятости SOCKS5 порта
#   - die()/warn(): унифицированный вывод ошибок
#   - PLUGIN_VERSION в баннере

set -e
set -u

PLUGIN_VERSION="1.6.0"
PLUGIN_DIR="$(dirname "$0")/plugin"

# ─────────────────────────────────────────────────────────────────────────────
# HELPERS
# ─────────────────────────────────────────────────────────────────────────────
warn() { echo "[WARN] $*" >&2; }
die()  { echo "[ERROR] $*" >&2; exit 1; }

# ─────────────────────────────────────────────────────────────────────────────
# UNINSTALL
# ─────────────────────────────────────────────────────────────────────────────
if [ "${1:-}" = "uninstall" ]; then
    echo "==> Stopping services..."
    /usr/local/opnsense/scripts/Xray/xray-service-control.php stop 2>/dev/null || true

    echo "==> Removing plugin files..."
    rm -f  /usr/local/opnsense/scripts/Xray/xray-service-control.php
    rm -f  /usr/local/opnsense/scripts/Xray/xray-testconnect.php
    rm -f  /usr/local/opnsense/scripts/Xray/xray-watchdog.php
    rm -f  /usr/local/opnsense/scripts/Xray/xray-ifstats.php
    rmdir  /usr/local/opnsense/scripts/Xray 2>/dev/null || true
    # BUG-11: удаляем конфиг ротации логов newsyslog
    rm -f  /etc/newsyslog.conf.d/xray.conf
    # Логи xray-core оставляем для истории (удалять вручную при необходимости):
    # rm -f /var/log/xray-core.log /var/log/xray-core.log.0.bz2 /var/log/xray-core.log.1.bz2 /var/log/xray-core.log.2.bz2
    rm -f  /usr/local/opnsense/service/conf/actions.d/actions_xray.conf
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/Xray       # включает ACL/ и Menu/
    rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray
    rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/Xray
    rm -f  /usr/local/etc/inc/plugins.inc.d/xray.inc
    rm -f  /usr/local/etc/rc.syshook.d/start/50-xray

    echo "==> Restarting configd..."
    service configd restart

    echo "==> Clearing cache..."
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml

    echo ""
    echo "=============================="
    echo "  os-xray plugin removed."
    echo "=============================="
    echo "Refresh browser with Ctrl+F5."
    exit 0
fi

# ─────────────────────────────────────────────────────────────────────────────
# DETECT EXISTING CONFIG
#
# Итерация 3: переписан с grep/sed/awk на PHP json_decode() + yaml-парсер.
#
# Почему grep/sed ненадёжны:
#   - минифицированный JSON (всё в одну строку): grep -o не найдёт
#   - нестандартные пробелы вокруг ":" → sed-паттерн не сработает
#   - экранированные символы внутри строк → ложные совпадения
#   - поля "port" встречаются в нескольких местах → grep берёт не то
#
# Решение: PHP выводит KEY='VALUE' в tmpfile → shell сорсит его.
# Передача путей через env-переменные (не через аргументы) — без инъекций.
# Control-chars в значениях фильтруются перед записью в shell-файл.
# ─────────────────────────────────────────────────────────────────────────────
detect_existing() {
    XRAY_JSON="/usr/local/etc/xray-core/config.json"
    T2S_YAML="/usr/local/tun2socks/config.yaml"

    # Явная инициализация (set -u не допускает необъявленных переменных)
    EXIST_SERVER=""
    EXIST_PORT_JSON=""
    EXIST_UUID=""
    EXIST_SNI=""
    EXIST_PUBKEY=""
    EXIST_SHORTID=""
    EXIST_FP=""
    EXIST_FLOW=""
    EXIST_SOCKS5=""
    EXIST_TUN=""
    EXIST_MTU=""
    EXIST_TUN_IP=""
    EXIST_TUN_GW=""
    HAS_EXISTING_CONFIG=0

    # PHP парсит оба файла и пишет shell-присваивания в tmpfile.
    # Tmpfile сорсится в текущем shell — переменные видны после return.
    _DET_TMP="/tmp/.xray_detect_$$.sh"

    _XRAY_JSON="$XRAY_JSON" _T2S_YAML="$T2S_YAML" \
    php -r '
        $xrayJson = getenv("_XRAY_JSON");
        $t2sYaml  = getenv("_T2S_YAML");

        $out = [
            "server"  => "",
            "port"    => "",
            "uuid"    => "",
            "sni"     => "",
            "pubkey"  => "",
            "shortid" => "",
            "fp"      => "",
            "flow"    => "",
            "socks5"  => "",
            "tun"     => "",
            "mtu"     => "",
        ];

        // ── Парсим xray config.json ──────────────────────────────────────────
        if (is_file($xrayJson)) {
            $raw = file_get_contents($xrayJson);
            if ($raw !== false) {
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    $vnext = $j["outbounds"][0]["settings"]["vnext"][0] ?? [];
                    $user  = $vnext["users"][0] ?? [];
                    $rs    = $j["outbounds"][0]["streamSettings"]["realitySettings"] ?? [];

                    $out["server"] = (string)($vnext["address"] ?? "");
                    $port = (int)($vnext["port"] ?? 0);
                    $out["port"]   = $port > 0 ? (string)$port : "";
                    $out["uuid"]   = (string)($user["id"]   ?? "");
                    $out["flow"]   = (string)($user["flow"] ?? "");
                    $out["sni"]     = (string)($rs["serverName"]  ?? "");
                    $out["pubkey"]  = (string)($rs["publicKey"]   ?? "");
                    $out["shortid"] = (string)($rs["shortId"]     ?? "");
                    $out["fp"]      = (string)($rs["fingerprint"] ?? "");

                    // SOCKS5 порт — из первого inbound
                    $s5 = (int)($j["inbounds"][0]["port"] ?? 0);
                    $out["socks5"] = $s5 > 0 ? (string)$s5 : "";
                }
            }
        }

        // ── Парсим tun2socks config.yaml ─────────────────────────────────────
        // Формат: "ключ: значение" без вложенности и кавычек
        if (is_file($t2sYaml)) {
            foreach (file($t2sYaml, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === "" || $line[0] === "#") continue;
                $colonPos = strpos($line, ":");
                if ($colonPos === false) continue;
                $key = trim(substr($line, 0, $colonPos));
                $val = trim(substr($line, $colonPos + 1));
                switch ($key) {
                    case "device":
                        $out["tun"] = $val;
                        break;
                    case "mtu":
                        $out["mtu"] = $val;
                        break;
                    case "proxy":
                        // "proxy: socks5://127.0.0.1:10808" → извлекаем порт
                        if ($out["socks5"] === "" && preg_match("/:(\\d+)$/", $val, $m)) {
                            $out["socks5"] = $m[1];
                        }
                        break;
                }
            }
        }

        // ── Выводим EXIST_KEY='"'"'VALUE'"'"' для shell source ───────────────
        // Экранирование: убираем control-chars, затем экранируем одинарные кавычки.
        // Паттерн '\''  означает: закрыть одинарную кавычку, вставить экранированную, открыть снова.
        foreach ($out as $k => $v) {
            $v    = preg_replace("/[\\x00-\\x1F\\x7F]/u", "", (string)$v);
            $safe = str_replace("'\''", "'\''\\'\'''\''", $v);
            echo "EXIST_" . strtoupper($k) . "='\''" . $safe . "'\''\n";
        }
    ' 2>/dev/null > "$_DET_TMP"

    # shellcheck disable=SC1090
    . "$_DET_TMP"
    rm -f "$_DET_TMP"

    # Читаем IP текущего TUN-интерфейса (если он уже поднят в системе)
    _TUN_IFACE="${EXIST_TUN:-proxytun2socks0}"
    EXIST_TUN_IP=$(ifconfig "$_TUN_IFACE" 2>/dev/null | awk '/inet /{print $2}') || EXIST_TUN_IP=""
    EXIST_TUN_GW=$(ifconfig "$_TUN_IFACE" 2>/dev/null | awk '/inet /{print $4}') || EXIST_TUN_GW=""

    if [ -n "$EXIST_SERVER" ] || [ -n "$EXIST_UUID" ]; then
        HAS_EXISTING_CONFIG=1
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# CHECK PORT AVAILABILITY
#
# Итерация 3: проверяет что socks5_port не занят другим процессом.
# sockstat(1) — стандартная FreeBSD утилита (аналог ss/netstat).
# Не прерывает установку — только предупреждение, чтобы не блокировать
# обновление уже работающего xray-core который сам держит этот порт.
# ─────────────────────────────────────────────────────────────────────────────
check_port() {
    _PORT="${1:-10808}"
    if sockstat -4l 2>/dev/null | awk '{print $6}' | grep -q ":${_PORT}$"; then
        warn "Port ${_PORT} is already in use by another process:"
        sockstat -4l 2>/dev/null | awk -v p=":${_PORT}" '$6 ~ p {print "       " $0}'
        warn "socks5_port=${_PORT} may conflict. Change it in GUI after install if needed."
        return 1
    fi
    return 0
}

# ─────────────────────────────────────────────────────────────────────────────
# IMPORT EXISTING CONFIG INTO OPNsense config.xml
# ─────────────────────────────────────────────────────────────────────────────
import_existing_config() {
    echo "==> Importing existing xray/tun2socks config into OPNsense..."

    _SOCKS5="${EXIST_SOCKS5:-10808}"
    _TUN="${EXIST_TUN:-proxytun2socks0}"
    _MTU="${EXIST_MTU:-1500}"
    _FLOW="${EXIST_FLOW:-xtls-rprx-vision}"
    _FP="${EXIST_FP:-chrome}"
    _SNI="${EXIST_SNI:-}"
    _PUBKEY="${EXIST_PUBKEY:-}"
    _SHORTID="${EXIST_SHORTID:-}"
    _SERVER="${EXIST_SERVER:-}"
    _UUID="${EXIST_UUID:-}"
    _PORT="${EXIST_PORT_JSON:-443}"

    # Шаг 1: сериализуем значения в JSON через PHP + env-переменные.
    # env-переменные безопасны для передачи любых строк (пробелы, кавычки и т.д.)
    _TMP_JSON="/tmp/.xray_import_$$.json"

    _S="$_SERVER" _P="$_PORT" _U="$_UUID" _FL="$_FLOW" \
    _SN="$_SNI" _PK="$_PUBKEY" _SI="$_SHORTID" _FP2="$_FP" \
    _S5="$_SOCKS5" _TN="$_TUN" _MT="$_MTU" \
    php -r '
        echo json_encode([
            "server"  => getenv("_S"),
            "port"    => (int)getenv("_P") ?: 443,
            "uuid"    => getenv("_U"),
            "flow"    => getenv("_FL"),
            "sni"     => getenv("_SN"),
            "pubkey"  => getenv("_PK"),
            "shortid" => getenv("_SI"),
            "fp"      => getenv("_FP2"),
            "socks5"  => (int)getenv("_S5") ?: 10808,
            "tun"     => getenv("_TN"),
            "mtu"     => (int)getenv("_MT") ?: 1500,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ' > "$_TMP_JSON" 2>/dev/null

    if [ ! -s "$_TMP_JSON" ]; then
        warn "Could not serialize config — fill fields manually in GUI."
        rm -f "$_TMP_JSON"
        return
    fi

    # Шаг 2: PHP читает JSON из tmpfile и записывает в OPNsense config.xml.
    # Heredoc с 'PHPEOF' — shell не интерполирует $-переменные внутри.
    #
    # BUG-9 FIX: config.inc ищется PHP по include_path.
    # На этапе установки CWD может быть любым (часто /root или /tmp),
    # поэтому явно добавляем путь OPNsense через set_include_path() до вызова require.
    # config.inc расположен в /usr/local/etc/inc/ на всех OPNsense-системах 25.x.
    _XRAY_JSON="$_TMP_JSON" php << 'PHPEOF'
<?php
// BUG-9 FIX: явно устанавливаем include_path перед require_once.
// Без этого при нестандартном CWD (например /root или /tmp) PHP не найдёт config.inc.
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

$jsonFile = getenv('_XRAY_JSON');
$raw = file_get_contents($jsonFile);
if ($raw === false) { echo "ERROR: cannot read tmp json\n"; exit(1); }
$d = json_decode($raw, true);
if (!is_array($d)) { echo "ERROR: bad json in tmp file\n"; exit(1); }

$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();

if (!isset($obj->OPNsense))       { $obj->addChild('OPNsense'); }
if (!isset($obj->OPNsense->xray)) { $obj->OPNsense->addChild('xray'); }
$x = $obj->OPNsense->xray;
if (!isset($x->general))          { $x->addChild('general'); }
if (!isset($x->instance))         { $x->addChild('instance'); }

$x->general->enabled    = '1';
$i = $x->instance;
$i->server_address      = $d['server'];
$i->server_port         = (string)$d['port'];
$i->uuid                = $d['uuid'];
$i->flow                = $d['flow'];
$i->reality_sni         = $d['sni'];
$i->reality_pubkey      = $d['pubkey'];
$i->reality_shortid     = $d['shortid'];
$i->reality_fingerprint = $d['fp'];
$i->socks5_port         = (string)$d['socks5'];
$i->tun_interface       = $d['tun'];
$i->mtu                 = (string)$d['mtu'];
$i->loglevel            = 'warning';

$cfg->save();
echo "Config imported OK\n";
PHPEOF

    _PHP_EXIT=$?
    rm -f "$_TMP_JSON"

    if [ "$_PHP_EXIT" -eq 0 ]; then
        echo "[OK]  Existing config imported into OPNsense."
    else
        warn "Could not auto-import config — fill fields manually in GUI."
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# INSTALL
# ─────────────────────────────────────────────────────────────────────────────
echo "============================================================"
echo "  os-xray plugin installer v${PLUGIN_VERSION}"
echo "============================================================"
echo ""

# ── Шаг 1: Проверка бинарников ───────────────────────────────────────────────
echo "==> Step 1: Checking binaries..."
BINARIES_OK=1

if [ ! -f /usr/local/bin/xray-core ]; then
    warn "xray-core NOT found at /usr/local/bin/xray-core"
    echo "       Download: https://github.com/XTLS/Xray-core/releases"
    echo "       fetch -o /tmp/xray.zip <URL-for-Xray-freebsd-64.zip>"
    echo "       cd /tmp && unzip xray.zip xray && install -m 0755 xray /usr/local/bin/xray-core"
    BINARIES_OK=0
else
    XRAY_VER=$(/usr/local/bin/xray-core version 2>/dev/null | head -1 || echo 'unknown')
    echo "[OK]  xray-core: $XRAY_VER"
fi

if [ ! -f /usr/local/tun2socks/tun2socks ]; then
    warn "tun2socks NOT found at /usr/local/tun2socks/tun2socks"
    echo "       Download: https://github.com/xjasonlyu/tun2socks/releases"
    echo "       fetch -o /tmp/t2s.zip <URL-for-tun2socks-freebsd-amd64.zip>"
    echo "       cd /tmp && unzip t2s.zip && mkdir -p /usr/local/tun2socks"
    echo "       install -m 0755 tun2socks-freebsd-amd64 /usr/local/tun2socks/tun2socks"
    BINARIES_OK=0
else
    echo "[OK]  tun2socks found"
fi

if [ "$BINARIES_OK" = "0" ]; then
    echo ""
    warn "One or more binaries are missing. Plugin will be installed,"
    warn "but Xray will NOT start until binaries are in place."
fi

# ── Шаг 2: Определение существующего конфига ─────────────────────────────────
echo ""
echo "==> Step 2: Detecting existing configuration..."
detect_existing

if [ "$HAS_EXISTING_CONFIG" = "1" ]; then
    echo "[FOUND] Existing xray/tun2socks config detected:"
    [ -n "${EXIST_SERVER:-}"  ] && echo "        Server:      ${EXIST_SERVER}:${EXIST_PORT_JSON:-443}"
    [ -n "${EXIST_UUID:-}"    ] && echo "        UUID:        ${EXIST_UUID}"
    [ -n "${EXIST_FLOW:-}"    ] && echo "        Flow:        ${EXIST_FLOW}"
    [ -n "${EXIST_SNI:-}"     ] && echo "        SNI:         ${EXIST_SNI}"
    [ -n "${EXIST_PUBKEY:-}"  ] && echo "        PublicKey:   ${EXIST_PUBKEY}"
    [ -n "${EXIST_SHORTID:-}" ] && echo "        ShortID:     ${EXIST_SHORTID}"
    [ -n "${EXIST_TUN:-}"     ] && echo "        TUN:         ${EXIST_TUN}"
    [ -n "${EXIST_TUN_IP:-}"  ] && echo "        TUN IP:      ${EXIST_TUN_IP}"
    [ -n "${EXIST_TUN_GW:-}"  ] && echo "        TUN Gateway: ${EXIST_TUN_GW}"
    [ -n "${EXIST_MTU:-}"     ] && echo "        MTU:         ${EXIST_MTU}"
    [ -n "${EXIST_SOCKS5:-}"  ] && echo "        SOCKS5 port: ${EXIST_SOCKS5}"
else
    echo "[INFO] No existing xray/tun2socks config found — fill fields manually in GUI."
fi

# ── Шаг 2.5: Проверка занятости SOCKS5 порта ─────────────────────────────────
echo ""
echo "==> Step 2.5: Checking SOCKS5 port availability..."
_CHECK_PORT="${EXIST_SOCKS5:-10808}"

# Если xray-core уже запущен — он сам держит порт, предупреждение лишнее
_XRAY_RUNNING=0
if [ -f /var/run/xray_core.pid ]; then
    _XRAY_PID=$(cat /var/run/xray_core.pid 2>/dev/null || echo "0")
    kill -0 "$_XRAY_PID" 2>/dev/null && _XRAY_RUNNING=1 || true
fi

if [ "$_XRAY_RUNNING" = "1" ]; then
    echo "[SKIP] xray-core is running — port ${_CHECK_PORT} is held by xray itself."
elif check_port "$_CHECK_PORT"; then
    echo "[OK]  Port ${_CHECK_PORT} is available."
fi
# check_port при занятом порте уже вывел warn — установка продолжается

# ── Шаг 3: Установка файлов плагина ──────────────────────────────────────────
echo ""
echo "==> Step 3: Installing plugin files..."

install -d /usr/local/opnsense/scripts/Xray
install -m 0755 "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
                /usr/local/opnsense/scripts/Xray/
install -m 0755 "$PLUGIN_DIR/scripts/Xray/xray-testconnect.php" \
                /usr/local/opnsense/scripts/Xray/
# E1: watchdog — автоперезапуск процессов через cron
install -m 0755 "$PLUGIN_DIR/scripts/Xray/xray-watchdog.php" \
                /usr/local/opnsense/scripts/Xray/
# E4: статистика TUN-интерфейса для панели Diagnostics
install -m 0755 "$PLUGIN_DIR/scripts/Xray/xray-ifstats.php" \
                /usr/local/opnsense/scripts/Xray/

install -m 0644 "$PLUGIN_DIR/service/conf/actions.d/actions_xray.conf" \
                /usr/local/opnsense/service/conf/actions.d/

install -d /usr/local/opnsense/mvc/app/models/OPNsense/Xray/Menu
install -d /usr/local/opnsense/mvc/app/models/OPNsense/Xray/ACL
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/ACL/ACL.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/Xray/ACL/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/General.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/Xray/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/General.php" \
                /usr/local/opnsense/mvc/app/models/OPNsense/Xray/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/Xray/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.php" \
                /usr/local/opnsense/mvc/app/models/OPNsense/Xray/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Menu/Menu.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/Xray/Menu/

install -d /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/Api
install -d /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/forms
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/IndexController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/GeneralController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/ServiceController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/ImportController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/forms/general.xml" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/forms/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/forms/instance.xml" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/forms/

install -d /usr/local/opnsense/mvc/app/views/OPNsense/Xray
install -m 0644 "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/general.volt" \
                /usr/local/opnsense/mvc/app/views/OPNsense/Xray/

install -m 0644 "$PLUGIN_DIR/etc/inc/plugins.inc.d/xray.inc" \
                /usr/local/etc/inc/plugins.inc.d/

install -d /usr/local/etc/rc.syshook.d/start
install -m 0755 "$PLUGIN_DIR/etc/rc.syshook.d/start/50-xray" \
                /usr/local/etc/rc.syshook.d/start/

# BUG-11: ротация /var/log/xray-core.log через newsyslog
# Без этого лог растёт бесконечно (файл появился после BUG-7 fix).
# Ротация: при превышении 600 KB, 3 архива, bzip2-сжатие.
install -d /etc/newsyslog.conf.d
install -m 0644 "$PLUGIN_DIR/etc/newsyslog.conf.d/xray.conf" \
                /etc/newsyslog.conf.d/

install -d -m 0750 /usr/local/etc/xray-core
install -d -m 0750 /usr/local/tun2socks

echo "[OK]  Plugin files installed."

# ── Шаг 4: Импорт существующего конфига ──────────────────────────────────────
echo ""
echo "==> Step 4: Importing existing config (if found)..."
if [ "$HAS_EXISTING_CONFIG" = "1" ]; then
    import_existing_config
else
    echo "[SKIP] No existing config to import."
fi

# ── Шаг 5: Перезапуск configd ─────────────────────────────────────────────────
echo ""
echo "==> Step 5: Restarting configd..."
service configd restart

# ── Шаг 6: Очистка кешей ──────────────────────────────────────────────────────
echo ""
echo "==> Step 6: Clearing cache..."
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/PHP_errors.log

# ─────────────────────────────────────────────────────────────────────────────
# SUMMARY
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "============================================================"
echo "  os-xray v${PLUGIN_VERSION} installed successfully!"
echo "============================================================"
echo ""

if [ "$HAS_EXISTING_CONFIG" = "1" ]; then
    echo "  Existing config was detected and imported automatically."
    echo "  Your settings are already loaded in the GUI."
    echo ""
    echo "  Quick steps:"
    echo "  1. Refresh browser (Ctrl+F5) → VPN → Xray"
    echo "  2. Check that Instance tab shows your settings"
    echo "  3. General tab → verify 'Enable Xray' is checked"
    echo "  4. Click Apply"
    echo ""
else
    echo "  Quick steps:"
    echo "  1. Refresh browser (Ctrl+F5) → VPN → Xray"
    echo "  2. Instance tab → 'Import VLESS link' → paste link → Parse & Fill"
    echo "  3. General tab → check 'Enable Xray'"
    echo "  4. Click Apply"
    echo ""
fi

MEMO_TUN="${EXIST_TUN:-proxytun2socks0}"
MEMO_TUN_IP="${EXIST_TUN_IP:-<TUN_IP>}"
MEMO_TUN_CIDR="${EXIST_TUN_IP:+${EXIST_TUN_IP}/30}"
MEMO_TUN_CIDR="${MEMO_TUN_CIDR:-<e.g. 10.255.0.1/30>}"

echo "  OPNsense interface & gateway setup:"
echo ""
echo "  5. Interfaces → Assignments"
echo "       + Add: $MEMO_TUN"
echo "       Enable interface ✓"
echo "       IPv4 Configuration Type: Static"
echo "       IPv4 Address: $MEMO_TUN_CIDR"
echo "       Prevent interface removal: ✓  (обязательно!)"
echo ""
echo "  6. System → Gateways → Configuration → Add"
echo "       Interface:             <your $MEMO_TUN interface name>"
echo "       Gateway IP:            $MEMO_TUN_IP"
echo "       Name:                  PROXYTUN_GW"
echo "       Far Gateway:           ✓  (обязательно!)"
echo "       Disable GW monitoring: ✓"
echo ""
echo "  7. Firewall → Aliases → Add"
echo "       Type: Network/Host(s)"
echo "       Add IPs/domains to route via VPN"
echo ""
echo "  8. Firewall → Rules → LAN → Add"
echo "       Source:      LAN net"
echo "       Destination: <your alias>"
echo "       Gateway:     PROXYTUN_GW"
echo ""
echo "  To uninstall: sh install.sh uninstall"
echo ""
