#!/bin/sh
# os-xray OPNsense Plugin Installer
# Xray-core (VLESS+Reality) + tun2socks
# Tested on OPNsense 25.x / FreeBSD 14.3
# Author: Меркулов Павел Сергеевич
#
# Usage:
#   sh install.sh            — install
#   sh install.sh uninstall  — remove

set -e

PLUGIN_DIR="$(dirname "$0")/plugin"

# ─────────────────────────────────────────────────────────────────────────────
# UNINSTALL
# ─────────────────────────────────────────────────────────────────────────────
if [ "$1" = "uninstall" ]; then
    echo "==> Stopping services..."
    /usr/local/opnsense/scripts/Xray/xray-service-control.php stop 2>/dev/null || true

    echo "==> Removing plugin files..."
    rm -f  /usr/local/opnsense/scripts/Xray/xray-service-control.php
    rmdir  /usr/local/opnsense/scripts/Xray 2>/dev/null || true
    rm -f  /usr/local/opnsense/service/conf/actions.d/actions_xray.conf
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/Xray
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
# ─────────────────────────────────────────────────────────────────────────────
detect_existing() {
    XRAY_JSON="/usr/local/etc/xray-core/config.json"
    T2S_YAML="/usr/local/tun2socks/config.yaml"

    EXIST_SERVER=""
    EXIST_UUID=""
    EXIST_SNI=""
    EXIST_PUBKEY=""
    EXIST_SHORTID=""
    EXIST_FP=""
    EXIST_FLOW=""
    EXIST_SOCKS5=""
    EXIST_TUN=""
    EXIST_MTU=""

    # Парсим xray config.json
    if [ -f "$XRAY_JSON" ]; then
        EXIST_SERVER=$(grep -o '"address"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*"address"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')
        EXIST_UUID=$(grep -o '"id"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*"id"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')
        EXIST_PORT_JSON=$(grep -o '"port"[[:space:]]*:[[:space:]]*[0-9]*' "$XRAY_JSON" 2>/dev/null | grep -v "10808\|10809" | head -1 | sed 's/.*:[[:space:]]*//')
        EXIST_SNI=$(grep -o '"serverName"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*"serverName"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')
        EXIST_PUBKEY=$(grep -o '"publicKey"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*"publicKey"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')
        EXIST_SHORTID=$(grep -o '"shortId"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*"shortId"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')
        EXIST_FP=$(grep -o '"fingerprint"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*"fingerprint"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')
        EXIST_FLOW=$(grep -o '"flow"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*"flow"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')
        EXIST_SOCKS5=$(grep -o '"port"[[:space:]]*:[[:space:]]*10[0-9]*' "$XRAY_JSON" 2>/dev/null | head -1 | sed 's/.*:[[:space:]]*//')
    fi

    # Парсим tun2socks config.yaml
    if [ -f "$T2S_YAML" ]; then
        EXIST_TUN=$(grep '^device:' "$T2S_YAML" 2>/dev/null | awk '{print $2}')
        EXIST_MTU=$(grep '^mtu:' "$T2S_YAML" 2>/dev/null | awk '{print $2}')
        if [ -z "$EXIST_SOCKS5" ]; then
            EXIST_SOCKS5=$(grep '^proxy:' "$T2S_YAML" 2>/dev/null | grep -o ':[0-9]*$' | tr -d ':')
        fi
    fi

    # Определяем IP TUN интерфейса если он уже поднят
    TUN_IFACE="${EXIST_TUN:-proxytun2socks0}"
    EXIST_TUN_IP=$(ifconfig "$TUN_IFACE" 2>/dev/null | grep 'inet ' | awk '{print $2}')
    EXIST_TUN_GW=$(ifconfig "$TUN_IFACE" 2>/dev/null | grep 'inet ' | awk '{print $4}')

    # Есть ли вообще существующий конфиг?
    if [ -n "$EXIST_SERVER" ] || [ -n "$EXIST_UUID" ]; then
        HAS_EXISTING_CONFIG=1
    else
        HAS_EXISTING_CONFIG=0
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# IMPORT EXISTING CONFIG INTO OPNsense config.xml
# ─────────────────────────────────────────────────────────────────────────────
import_existing_config() {
    echo "==> Importing existing xray/tun2socks config into OPNsense..."

    SOCKS5_PORT="${EXIST_SOCKS5:-10808}"
    TUN="${EXIST_TUN:-proxytun2socks0}"
    MTU="${EXIST_MTU:-1500}"
    FLOW="${EXIST_FLOW:-xtls-rprx-vision}"
    FP="${EXIST_FP:-chrome}"
    SNI="${EXIST_SNI:-}"
    PUBKEY="${EXIST_PUBKEY:-}"
    SHORTID="${EXIST_SHORTID:-}"
    SERVER="${EXIST_SERVER:-}"
    UUID_VAL="${EXIST_UUID:-}"
    PORT_VAL="${EXIST_PORT_JSON:-443}"

    # Шаг 1: сериализуем данные в JSON через PHP + env-переменные (без интерполяции строк).
    _TMP_JSON="/tmp/.xray_import_$$.json"
    _S="$SERVER" _P="$PORT_VAL" _U="$UUID_VAL" _FL="$FLOW" \
    _SN="$SNI" _PK="$PUBKEY" _SI="$SHORTID" _FP="$FP" \
    _S5="$SOCKS5_PORT" _TN="$TUN" _MT="$MTU" \
    php -r 'echo json_encode([
        "server"  => getenv("_S"),
        "port"    => (int)getenv("_P") ?: 443,
        "uuid"    => getenv("_U"),
        "flow"    => getenv("_FL"),
        "sni"     => getenv("_SN"),
        "pubkey"  => getenv("_PK"),
        "shortid" => getenv("_SI"),
        "fp"      => getenv("_FP"),
        "socks5"  => (int)getenv("_S5") ?: 10808,
        "tun"     => getenv("_TN"),
        "mtu"     => (int)getenv("_MT") ?: 1500,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' > "$_TMP_JSON" 2>/dev/null

    if [ ! -s "$_TMP_JSON" ]; then
        echo "[WARN] Could not serialize config — fill fields manually in GUI."
        rm -f "$_TMP_JSON"
        return
    fi

    # Шаг 2: PHP читает JSON из файла по пути в env-переменной.
    # Heredoc с 'PHPEOF' — shell не трогает $-переменные внутри.
    _XRAY_JSON="$_TMP_JSON" php << 'PHPEOF'
<?php
require_once('config.inc');
$jsonFile = getenv('_XRAY_JSON');
$d = json_decode(file_get_contents($jsonFile), true);
if (!is_array($d)) { echo "ERROR: bad json\n"; exit(1); }
$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();
if (!isset($obj->OPNsense))       { $obj->addChild('OPNsense'); }
if (!isset($obj->OPNsense->xray)) { $obj->OPNsense->addChild('xray'); }
$x = $obj->OPNsense->xray;
if (!isset($x->general))  { $x->addChild('general'); }
if (!isset($x->instance)) { $x->addChild('instance'); }
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

    if [ $_PHP_EXIT -eq 0 ]; then
        echo "[OK]  Existing config imported into OPNsense."
    else
        echo "[WARN] Could not auto-import config — fill fields manually in GUI."
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# INSTALL
# ─────────────────────────────────────────────────────────────────────────────
echo "==> Step 1: Checking binaries..."

if [ ! -f /usr/local/bin/xray-core ]; then
    echo "[WARN] xray-core NOT found at /usr/local/bin/xray-core"
    echo "       Download: https://github.com/XTLS/Xray-core/releases"
    echo "       fetch -o /usr/local/bin/xray-core <URL> && chmod +x /usr/local/bin/xray-core"
else
    XRAY_VER=$(/usr/local/bin/xray-core version 2>/dev/null | head -1 || echo 'unknown')
    echo "[OK]  xray-core: $XRAY_VER"
fi

if [ ! -f /usr/local/tun2socks/tun2socks ]; then
    echo "[WARN] tun2socks NOT found at /usr/local/tun2socks/tun2socks"
    echo "       Download: https://github.com/xjasonlyu/tun2socks/releases"
else
    echo "[OK]  tun2socks found"
fi

echo ""
echo "==> Step 2: Detecting existing configuration..."
detect_existing

if [ "$HAS_EXISTING_CONFIG" = "1" ]; then
    echo "[FOUND] Existing xray/tun2socks config detected:"
    [ -n "$EXIST_SERVER"  ] && echo "        Server:      $EXIST_SERVER:${EXIST_PORT_JSON:-443}"
    [ -n "$EXIST_UUID"    ] && echo "        UUID:        $EXIST_UUID"
    [ -n "$EXIST_FLOW"    ] && echo "        Flow:        $EXIST_FLOW"
    [ -n "$EXIST_SNI"     ] && echo "        SNI:         $EXIST_SNI"
    [ -n "$EXIST_PUBKEY"  ] && echo "        PublicKey:   $EXIST_PUBKEY"
    [ -n "$EXIST_SHORTID" ] && echo "        ShortID:     $EXIST_SHORTID"
    [ -n "$EXIST_TUN"     ] && echo "        TUN:         $EXIST_TUN"
    [ -n "$EXIST_TUN_IP"  ] && echo "        TUN IP:      $EXIST_TUN_IP"
    [ -n "$EXIST_TUN_GW"  ] && echo "        TUN Gateway: $EXIST_TUN_GW"
    [ -n "$EXIST_MTU"     ] && echo "        MTU:         $EXIST_MTU"
    [ -n "$EXIST_SOCKS5"  ] && echo "        SOCKS5 port: $EXIST_SOCKS5"
else
    echo "[INFO] No existing xray/tun2socks config found — fill fields manually in GUI."
fi

echo ""
echo "==> Step 3: Installing plugin files..."

install -d /usr/local/opnsense/scripts/Xray
install -m 0755 "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
                /usr/local/opnsense/scripts/Xray/

install -m 0644 "$PLUGIN_DIR/service/conf/actions.d/actions_xray.conf" \
                /usr/local/opnsense/service/conf/actions.d/

install -d /usr/local/opnsense/mvc/app/models/OPNsense/Xray/Menu
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

install -d -m 0750 /usr/local/etc/xray-core
install -d -m 0750 /usr/local/tun2socks

echo ""
echo "==> Step 4: Importing existing config (if found)..."
if [ "$HAS_EXISTING_CONFIG" = "1" ]; then
    import_existing_config
else
    echo "[SKIP] No existing config to import."
fi

echo ""
echo "==> Step 5: Restarting configd..."
service configd restart

echo ""
echo "==> Step 6: Clearing cache..."
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/PHP_errors.log

# ─────────────────────────────────────────────────────────────────────────────
# SUMMARY
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "============================================================"
echo "  os-xray plugin installed!"
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

# Определяем TUN интерфейс для памятки
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
echo ""
echo "  6. System → Gateways → Configuration → Add"
echo "       Interface:   <your $MEMO_TUN interface name>"
echo "       Gateway IP:  $MEMO_TUN_IP"
echo "       Name:        PROXYTUN_GW"
echo "       Far Gateway: ✓  (обязательно!)"
echo "       Disable GW monitoring: ✓"
echo ""
echo "  7. Firewall → Settings → Normalization → Add  (опционально, для Xray не требуется)"
echo "       Interface: <your $MEMO_TUN interface name>"
echo "       Protocol:  TCP"
echo "       Max MSS:   1380"
echo ""
echo "  8. Firewall → Aliases → Add"
echo "       Type: Network/Host(s)"
echo "       Add IPs/domains to route via VPN"
echo ""
echo "  9. Firewall → Rules → LAN → Add"
echo "       Source:      LAN net"
echo "       Destination: <your alias>"
echo "       Gateway:     PROXYTUN_GW"
echo ""
echo "  To uninstall: sh install.sh uninstall"
echo ""
