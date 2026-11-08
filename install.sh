#!/bin/sh
# os-xray OPNsense Plugin Installer
# Xray-core (VLESS+Reality) + tun2socks
# Tested on OPNsense 25.x / FreeBSD 14.x
# Author: Меркулов Павел Сергеевич
#
# Repository: https://github.com/ha-harbor-ws/os-xray
# Branch:     develop
#
# Usage:
#   sh install.sh              — install
#   sh install.sh uninstall    — remove plugin (packages only if installed by os-xray)
#   sh install.sh fulluninstall — remove plugin + all created configs/logs/startup
#                                 scripts + packages (bird2, os-dnstap-bgp, tun2socks,
#                                 xray-core). Keeps unbound and /conf/config.xml.
#
# Remote install:
#   cd /tmp
#   git clone -b develop https://github.com/ha-harbor-ws/os-xray.git
#   cd os-xray && sh install.sh

set -e
set -u

PLUGIN_VERSION="3.1.0"
REPO_OWNER="ha-harbor-ws"
REPO_NAME="os-xray"
REPO_BRANCH="develop"
REPO_URL="https://github.com/${REPO_OWNER}/${REPO_NAME}.git"
ARCHIVE_URL="https://github.com/${REPO_OWNER}/${REPO_NAME}/archive/refs/heads/${REPO_BRANCH}.tar.gz"
PLUGIN_DIR="$(dirname "$0")/plugin"
VERSION_FILE="/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt"
XRAY_PKG_BASELINE="/var/db/os-xray/pkg-baseline"
UNBOUND_DNSTAP_PKG_URL="https://github.com/ha-harbor-ws/os-unbound/releases/latest/download/unbound-latest.pkg"
DTAP_GH_REPO="ha-harbor-ws/dnstap-bgp"
DTAP_PKG_URL="https://github.com/${DTAP_GH_REPO}/releases/latest/download/os-dnstap-bgp-latest.pkg"
T2S_BIN="/usr/local/tun2socks/tun2socks"
T2S_DIR="/usr/local/tun2socks"
T2S_GH_REPO="xjasonlyu/tun2socks"
T2S_ZIP_URL="https://github.com/${T2S_GH_REPO}/releases/latest/download/tun2socks-freebsd-amd64.zip"

# ─────────────────────────────────────────────────────────────────────────────
# HELPERS
# ─────────────────────────────────────────────────────────────────────────────
warn() { echo "[WARN] $*" >&2; }
die()  { echo "[ERROR] $*" >&2; exit 1; }

xray_unbound_bin() {
    for _ub in /usr/local/sbin/unbound /usr/sbin/unbound; do
        if [ -x "$_ub" ]; then
            echo "$_ub"
            return 0
        fi
    done
    return 1
}

# Stock OPNsense unbound is built without DNSTAP. Our replacement prints --enable-dnstap in unbound -V.
xray_unbound_has_dnstap() {
    _ub="$(xray_unbound_bin 2>/dev/null || true)"
    [ -n "$_ub" ] || return 1
    "$_ub" -V 2>&1 | grep -q -- '--enable-dnstap'
}

# Reinstall/upgrade without uninstall: keep live configs, still refresh packages and rc/syshooks.
xray_keep_config() {
    [ "${XRAY_KEEP_CONFIG:-0}" = "1" ]
}

xray_skip_existing_config() {
    if xray_keep_config && [ -e "$1" ]; then
        echo "[SKIP] $1 (reinstall — keep configuration)"
        return 0
    fi
    return 1
}

xray_copy_dnstap_bgp_samples() {
    _copied=0
    for _src in /usr/local/etc/dnstap-bgp/*.sample /usr/local/etc/rc.conf.d/dnstap_bgp.sample; do
        [ -f "$_src" ] || continue
        _dst="${_src%.sample}"
        if [ -e "$_dst" ]; then
            echo "[OK]  $_dst exists — skip"
            continue
        fi
        _dir=$(dirname "$_dst")
        [ -d "$_dir" ] || mkdir -p "$_dir"
        install -m 0644 "$_src" "$_dst"
        echo "[OK]  $_dst (from $(basename "$_src"))"
        _copied=1
    done
    if [ "$_copied" = "0" ]; then
        echo "[INFO] dnstap-bgp sample configs: nothing new to copy"
    fi
}

xray_seed_dnstap_ipv6_default() {
    if xray_keep_config; then
        echo "[SKIP] dnstap-bgp.conf ipv6 (reinstall — keep configuration)"
        return 0
    fi
    _conf="/usr/local/etc/dnstap-bgp/dnstap-bgp.conf"
    if [ ! -f "$_conf" ]; then
        echo "[INFO] $_conf missing — skip ipv6 default"
        return
    fi
    if grep -qE '^ipv6[[:space:]]*=' "$_conf"; then
        sed -i '' -e 's/^ipv6[[:space:]]*=.*/ipv6 = true/' "$_conf"
    else
        printf '\nipv6 = true\n' >> "$_conf"
    fi
    echo "[OK]  $_conf ipv6 = true"
}

xray_dnstap_mv_if_missing() {
    _old="$1"
    _new="$2"
    if [ -f "$_old" ] && [ ! -f "$_new" ]; then
        mv "$_old" "$_new"
        echo "[OK]  migrated $_old → $_new"
    fi
}

# Align live files/keys with dnstap-bgp 1.5.x (PROXY/DIRECT/BLOCK).
xray_migrate_dnstap_pkg() {
    _dir="/usr/local/etc/dnstap-bgp"
    [ -d "$_dir" ] || mkdir -p "$_dir"
    xray_dnstap_mv_if_missing "$_dir/blocked.txt" "$_dir/proxy.txt"
    if [ -f "$_dir/blocked.txt" ]; then
        rm -f "$_dir/blocked.txt"
        echo "[OK]  removed $_dir/blocked.txt"
    fi
    xray_dnstap_mv_if_missing "$_dir/unblocked.txt" "$_dir/direct.txt"
    xray_dnstap_mv_if_missing "$_dir/blocked-extra.txt" "$_dir/proxy-extra.txt"
    xray_dnstap_mv_if_missing "$_dir/unblocked-extra.txt" "$_dir/direct-extra.txt"
    xray_dnstap_mv_if_missing "$_dir/blocked-urls.txt" "$_dir/proxy-urls.txt"
    xray_dnstap_mv_if_missing "$_dir/unblocked-urls.txt" "$_dir/direct-urls.txt"
    if [ ! -f "$_dir/block.txt" ] && [ -f "$_dir/block.txt.sample" ]; then
        install -m 0644 "$_dir/block.txt.sample" "$_dir/block.txt"
        echo "[OK]  $_dir/block.txt (from block.txt.sample)"
    fi
    _conf="$_dir/dnstap-bgp.conf"
    if [ -f "$_conf" ]; then
        sed -i '' \
            -e 's/unblocked_domains/direct_domains/g' \
            -e 's/unproxy_domains/direct_domains/g' \
            -e 's/blocked_domains/proxy_domains/g' \
            -e 's/unblocked_communities/direct_communities/g' \
            -e 's/unproxy_communities/direct_communities/g' \
            -e 's/blocked_communities/proxy_communities/g' \
            -e 's|/unblocked.txt|/direct.txt|g' \
            -e 's|/unproxy.txt|/direct.txt|g' \
            -e 's|/blocked.txt|/proxy.txt|g' \
            "$_conf"
        if ! grep -qE '^[[:space:]]*block_domains[[:space:]]*=' "$_conf"; then
            _tmp="${_conf}.new"
            awk '
                /^\[/ && !ins {
                    print "block_domains = \"/usr/local/etc/dnstap-bgp/block.txt\""
                    print ""
                    ins=1
                }
                { print }
                END {
                    if (!ins) {
                        print ""
                        print "block_domains = \"/usr/local/etc/dnstap-bgp/block.txt\""
                    }
                }
            ' "$_conf" > "$_tmp" && mv "$_tmp" "$_conf"
            echo "[OK]  $_conf block_domains"
        fi
        echo "[OK]  $_conf keys for dnstap-bgp 1.5.x"
    fi
}

xray_seed_dnstap_proxy_url() {
    if xray_keep_config; then
        echo "[SKIP] dnstap proxy-urls.txt (reinstall — keep configuration)"
        return 0
    fi
    _urls="/usr/local/etc/dnstap-bgp/proxy-urls.txt"
    mkdir -p /usr/local/etc/dnstap-bgp
    if [ ! -f "$_urls" ]; then
        printf '%s\n' "# HTTP(S) URLs of domain lists. One URL per line." > "$_urls"
        chmod 0644 "$_urls"
    fi
    for _default in \
        "https://github.com/1andrevich/Re-filter-lists/releases/download/01082026/domains_all.lst" \
        "https://community.antifilter.download/list/domains.lst"
    do
        if grep -Fqx "$_default" "$_urls"; then
            echo "[OK]  $_urls already has $_default"
            continue
        fi
        printf '%s\n' "$_default" >> "$_urls"
        echo "[OK]  $_urls += $_default"
    done
}

# Default hydraponique geosite.dat category assignments (PROXY/DIRECT/BLOCK).
# Requires plugin scripts already installed under /usr/local/opnsense/scripts/Xray.
xray_seed_dnstap_geodat_categories() {
    if xray_keep_config; then
        echo "[SKIP] dnstap geodat categories (reinstall — keep configuration)"
        return 0
    fi
    _script="/usr/local/opnsense/scripts/Xray/xray-dnstap-unbound.php"
    if [ ! -f "$_script" ]; then
        warn "$_script missing — skip geodat category seed"
        return 0
    fi
    /usr/local/bin/php -r "require_once '$_script'; xray_dnstap_seed_default_geodat_categories();" \
        && echo "[OK]  dnstap default geodat categories seeded" \
        || warn "dnstap geodat category seed failed"
}

# Default Static Geodat IP list URL (geoip.dat) + category assignments.
xray_seed_static_geodat_defaults() {
    if xray_keep_config; then
        echo "[SKIP] static geodat defaults (reinstall — keep configuration)"
        return 0
    fi
    _script="/usr/local/opnsense/scripts/Xray/xray-static-conf.php"
    if [ ! -f "$_script" ]; then
        warn "$_script missing — skip static geodat seed"
        return 0
    fi
    /usr/local/bin/php "$_script" seed \
        && echo "[OK]  static default geodat URL/categories seeded" \
        || warn "static geodat seed failed"
}

xray_detect_wan_if() {
    /sbin/route -n get -inet default 2>/dev/null | awk '/interface:/{print $2; exit}'
}

xray_detect_wan_v4() {
    _if="${1:-}"
    [ -n "$_if" ] || _if="$(xray_detect_wan_if)"
    [ -n "$_if" ] || return 0
    /sbin/ifconfig "$_if" 2>/dev/null | awk '/inet / && $2 !~ /^127\./ && $2 != "0.0.0.0" && $0 !~ /vhid/ {print $2; exit}'
}

xray_detect_wan_v6() {
    _if="${1:-}"
    [ -z "$_if" ] && _if="$(/sbin/route -n get -inet6 default 2>/dev/null | awk '/interface:/{print $2; exit}')"
    [ -z "$_if" ] && _if="$(xray_detect_wan_if)"
    [ -n "$_if" ] || return 0
    /sbin/ifconfig "$_if" 2>/dev/null | awk '/inet6 / && $2 !~ /^[Ff][Ee]80:/ && $2 != "::1" && $2 != "::" && $0 !~ /vhid/ { gsub(/%.*/, "", $2); print $2; exit }'
}

xray_shell_fill_wan_bird() {
    if xray_skip_existing_config /usr/local/etc/bird/router_id.inc; then
        return 0
    fi
    _v4="$(xray_detect_wan_v4 || true)"
    _v6="$(xray_detect_wan_v6 || true)"
    if [ -n "$_v4" ]; then
        printf 'router id %s;\n' "$_v4" > /usr/local/etc/bird/router_id.inc
        chmod 0644 /usr/local/etc/bird/router_id.inc
        echo "[OK]  router id $_v4 (from WAN ifconfig)"
    else
        warn "WAN IPv4 not found for router id"
    fi
}

xray_write_bird_log_configs() {
    install -d -m 0755 /var/log/bird
    install -d -m 0755 /usr/local/etc/syslog-ng.conf.d
    install -d -m 0755 /usr/local/etc/newsyslog.conf.d
    if [ ! -f /var/log/bird/bird.log ]; then
        : > /var/log/bird/bird.log
        chmod 0640 /var/log/bird/bird.log
    fi
    if ! xray_skip_existing_config /usr/local/etc/syslog-ng.conf.d/bird.conf; then
    cat > /usr/local/etc/syslog-ng.conf.d/bird.conf << 'EOF'
# BIRD logs (os-xray): bird.conf uses "log syslog { warning };" by default
destination d_bird {
    file("/var/log/bird/bird.log"
         owner("root")
         group("wheel")
         perm(0640)
         create-dirs(yes)
    );
};

filter f_bird {
    program("^bird");
};

log {
    source(s_all);
    filter(f_bird);
    destination(d_bird);
    flags(final);
};
EOF
    chmod 0644 /usr/local/etc/syslog-ng.conf.d/bird.conf
    fi

    # logfile owner:group mode count size when flags pid
    # when=-  no time rotation; size=1000 KB; count=7; J=bzip2; C=create if missing
    if ! xray_skip_existing_config /usr/local/etc/newsyslog.conf.d/bird.conf; then
    cat > /usr/local/etc/newsyslog.conf.d/bird.conf << 'EOF'
# newsyslog rotation for BIRD (os-xray)
# count=7  size=1000KB  when=- (size only)  JC (bzip2 + create)
/var/log/bird/bird.log		root:wheel	640	7	1000	-	JC	/var/run/syslog-ng.pid
EOF
    chmod 0644 /usr/local/etc/newsyslog.conf.d/bird.conf
    fi
}

xray_write_dnstap_log_configs() {
    install -d -m 0755 /var/log/dnstap-bgp
    install -d -m 0755 /usr/local/etc/syslog-ng.conf.d
    install -d -m 0755 /usr/local/etc/newsyslog.conf.d
    if [ ! -f /var/log/dnstap-bgp/dnstap-bgp.log ]; then
        : > /var/log/dnstap-bgp/dnstap-bgp.log
        chmod 0640 /var/log/dnstap-bgp/dnstap-bgp.log
    fi
    if ! xray_skip_existing_config /usr/local/etc/syslog-ng.conf.d/dnstap-bgp.conf; then
    cat > /usr/local/etc/syslog-ng.conf.d/dnstap-bgp.conf << 'EOF'
# dnstap-bgp logs (os-xray): rc.d daemon -S -T dnstap_bgp
destination d_dnstap_bgp {
    file("/var/log/dnstap-bgp/dnstap-bgp.log"
         owner("root")
         group("wheel")
         perm(0640)
         create-dirs(yes)
    );
};

filter f_dnstap_bgp {
    program("^dnstap_bgp") or program("^dnstap-bgp");
};

log {
    source(s_all);
    filter(f_dnstap_bgp);
    destination(d_dnstap_bgp);
    flags(final);
};
EOF
    chmod 0644 /usr/local/etc/syslog-ng.conf.d/dnstap-bgp.conf
    fi

    if ! xray_skip_existing_config /usr/local/etc/newsyslog.conf.d/dnstap-bgp.conf; then
    cat > /usr/local/etc/newsyslog.conf.d/dnstap-bgp.conf << 'EOF'
# newsyslog rotation for dnstap-bgp (os-xray)
# count=7  size=1000KB  when=- (size only)  JC (bzip2 + create)
/var/log/dnstap-bgp/dnstap-bgp.log	root:wheel	640	7	1000	-	JC	/var/run/syslog-ng.pid
EOF
    chmod 0644 /usr/local/etc/newsyslog.conf.d/dnstap-bgp.conf
    fi
}

xray_remove_bird_log_configs() {
    rm -f /usr/local/etc/syslog-ng.conf.d/bird.conf
    rm -f /usr/local/etc/newsyslog.conf.d/bird.conf
}

xray_remove_dnstap_log_configs() {
    rm -f /usr/local/etc/syslog-ng.conf.d/dnstap-bgp.conf
    rm -f /usr/local/etc/newsyslog.conf.d/dnstap-bgp.conf
}

xray_pkg_file_version() {
    pkg query -F "$1" '%v' 2>/dev/null \
        || pkg info -F "$1" 2>/dev/null | awk '/^Version/{print $3; exit}'
}

# Record which of bird2 / unbound / os-dnstap-bgp were already on the system
# before the first os-xray install. Never overwrite an existing baseline.
# If the plugin is already on the system without a baseline, do not guess —
# uninstall will keep packages.
xray_pkg_baseline_init() {
    if [ -f "$XRAY_PKG_BASELINE" ]; then
        echo "[OK]  package baseline: $XRAY_PKG_BASELINE"
        return 0
    fi
    if [ "${CURRENT_VERSION:-not installed}" != "not installed" ]; then
        echo "[INFO] no package baseline (plugin already installed) — uninstall will not delete bird2/unbound/os-dnstap-bgp/tun2socks"
        return 0
    fi
    mkdir -p "$(dirname "$XRAY_PKG_BASELINE")"
    {
        echo "# os-xray: 1 = already present before first plugin install"
        for _p in bird2 unbound os-dnstap-bgp; do
            if pkg info -e "$_p" >/dev/null 2>&1; then
                echo "$_p=1"
            else
                echo "$_p=0"
            fi
        done
        if [ -x "$T2S_BIN" ]; then
            echo "tun2socks=1"
        else
            echo "tun2socks=0"
        fi
    } > "$XRAY_PKG_BASELINE"
    echo "[OK]  wrote package baseline $XRAY_PKG_BASELINE"
}

# True if the package was present before first os-xray install (or unknown).
xray_pkg_was_preinstalled() {
    if [ ! -f "$XRAY_PKG_BASELINE" ]; then
        return 0
    fi
    grep -q "^${1}=1$" "$XRAY_PKG_BASELINE"
}

# Strip name=... from an rc.conf-style file (handles ""YES"" leftovers sysrc misses).
xray_rc_delete_var() {
    _file="$1"
    _var="$2"
    [ -f "$_file" ] || return 0
    grep -qE "^[[:space:]]*${_var}=" "$_file" 2>/dev/null || return 0
    grep -vE "^[[:space:]]*${_var}=" "$_file" > "${_file}.osxray.tmp" && mv "${_file}.osxray.tmp" "$_file"
}

xray_rc_delete_var_all() {
    _var="$1"
    shift
    for _file in /etc/rc.conf /etc/rc.conf.local /usr/local/etc/rc.conf "$@"; do
        xray_rc_delete_var "$_file" "$_var"
    done
}

xray_pkg_is_older() {
    _a="${1:-}"
    _b="${2:-}"
    [ -n "$_a" ] && [ -n "$_b" ] || return 1
    _a="${_a#v}"
    _b="${_b#v}"
    [ "$(pkg version -t "$_a" "$_b" 2>/dev/null || true)" = "<" ]
}

xray_github_release_tag() {
    _json="/tmp/os-xray-gh-${2:-rel}.json"
    _tag=""
    if fetch -o "$_json" "https://api.github.com/repos/${1}/releases/latest" 2>/dev/null; then
        _tag=$(php -r '$j=json_decode((string)file_get_contents($argv[1]), true); echo is_array($j) ? (string)($j["tag_name"] ?? "") : "";' "$_json" 2>/dev/null || true)
    fi
    rm -f "$_json"
    echo "$_tag"
}

xray_t2s_local_version() {
    [ -x "$T2S_BIN" ] || return 1
    _out=$("$T2S_BIN" --version 2>&1 || true)
    [ -n "$_out" ] || _out=$("$T2S_BIN" -v 2>&1 || true)
    [ -n "$_out" ] || _out=$("$T2S_BIN" version 2>&1 || true)
    echo "$_out" | grep -oE 'v?[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1
}

xray_t2s_stop_running() {
    for _PIDFILE in /var/run/tun2socks_*.pid /var/run/tun2socks.pid; do
        [ -f "$_PIDFILE" ] || continue
        _PID=$(cat "$_PIDFILE" 2>/dev/null || echo "0")
        if kill -0 "$_PID" 2>/dev/null; then
            echo "  Stopping running tun2socks (PID $_PID)..."
            kill "$_PID" 2>/dev/null || true
        fi
    done
    sleep 1
}

# tun2socks from GitHub Releases (not a FreeBSD pkg): install or replace if newer.
xray_ensure_tun2socks() {
    echo "==> Checking tun2socks ..."
    _latest=$(xray_github_release_tag "$T2S_GH_REPO" t2s)
    _inst=""
    if [ -x "$T2S_BIN" ]; then
        _inst=$(xray_t2s_local_version || true)
        if [ -n "$_latest" ] && [ -n "$_inst" ] && ! xray_pkg_is_older "$_inst" "$_latest"; then
            echo "[OK]  tun2socks: ${_inst} (up to date)"
            return 0
        fi
        if [ -n "$_latest" ] && [ -n "$_inst" ]; then
            echo "[INFO] tun2socks $_inst → $_latest"
        elif [ -z "$_latest" ] && [ -n "$_inst" ]; then
            warn "GitHub API unavailable — leaving tun2socks $_inst"
            return 0
        else
            echo "[INFO] tun2socks present (version unknown) — installing GitHub latest"
        fi
    else
        warn "tun2socks NOT found — installing from GitHub..."
    fi
    _zip="/tmp/tun2socks-freebsd-amd64.zip"
    _ex="/tmp/tun2socks-extract"
    rm -rf "$_ex"
    mkdir -p "$_ex"
    if ! fetch -o "$_zip" "$T2S_ZIP_URL"; then
        warn "Failed to fetch $T2S_ZIP_URL"
        if [ ! -x "$T2S_BIN" ]; then
            echo "       Install manually: fetch -o /tmp/t2s.zip $T2S_ZIP_URL"
            echo "       cd /tmp && unzip t2s.zip && mkdir -p $T2S_DIR"
            echo "       install -m 0755 tun2socks-freebsd-amd64 $T2S_BIN"
            BINARIES_OK=0
        fi
        rm -f "$_zip"
        rmdir "$_ex" 2>/dev/null || true
        return 0
    fi
    if ! unzip -o "$_zip" -d "$_ex" >/dev/null 2>&1; then
        warn "unzip failed for tun2socks zip"
        if [ ! -x "$T2S_BIN" ]; then
            BINARIES_OK=0
        fi
        rm -f "$_zip"
        rm -rf "$_ex"
        return 0
    fi
    _src=""
    for _cand in \
        "$_ex/tun2socks-freebsd-amd64" \
        "$_ex/tun2socks" \
        "$_ex/tun2socks-freebsd-amd64-v3"
    do
        if [ -f "$_cand" ]; then
            _src="$_cand"
            break
        fi
    done
    if [ -z "$_src" ]; then
        _src=$(find "$_ex" -type f -name 'tun2socks*' ! -name '*.zip' | head -1)
    fi
    if [ -z "$_src" ] || [ ! -f "$_src" ]; then
        warn "tun2socks binary not found in zip"
        if [ ! -x "$T2S_BIN" ]; then
            BINARIES_OK=0
        fi
        rm -f "$_zip"
        rm -rf "$_ex"
        return 0
    fi
    if [ -x "$T2S_BIN" ]; then
        xray_t2s_stop_running
    fi
    mkdir -p "$T2S_DIR"
    if install -m 0755 "$_src" "$T2S_BIN"; then
        _now=$(xray_t2s_local_version || echo "${_latest:-installed}")
        echo "[OK]  tun2socks installed: $_now"
    else
        warn "Failed to install tun2socks to $T2S_BIN"
        BINARIES_OK=0
    fi
    rm -f "$_zip"
    rm -rf "$_ex"
}

# Unlock GitHub unbound so repo upgrade can replace it.
xray_unbound_unlock() {
    echo "==> Unlocking unbound (if pkg-locked)..."
    if pkg info -e unbound >/dev/null 2>&1 && [ "$(pkg query '%k' unbound 2>/dev/null || echo 0)" = "1" ]; then
        if pkg unlock -y unbound; then
            echo "[OK]  pkg unlock unbound"
        else
            warn "pkg unlock unbound failed"
        fi
    else
        echo "[OK]  unbound is not pkg-locked"
    fi
}

# Do not run pkg update here — it hangs on OPNsense; catalog is refreshed from the GUI.
xray_pkg_gui_update_notice() {
    echo ""
    echo "------------------------------------------------------------"
    echo "  Каталог пакетов этим скриптом не обновляется (pkg update не вызывается)."
    echo "  Обновите систему из веб-интерфейса OPNsense:"
    echo "    System → Firmware → Updates"
    echo "------------------------------------------------------------"
    printf "  Нажмите любую клавишу для продолжения... "
    if [ -r /dev/tty ]; then
        _stty=$(stty -g < /dev/tty 2>/dev/null || true)
        if [ -n "$_stty" ] && stty -icanon -echo min 1 time 0 < /dev/tty 2>/dev/null; then
            dd if=/dev/tty of=/dev/null bs=1 count=1 2>/dev/null || true
            stty "$_stty" < /dev/tty 2>/dev/null || true
        else
            read -r _ </dev/tty || true
        fi
    else
        read -r _ || true
    fi
    echo ""
    echo ""
}

xray_pkg_repo_version() {
    pkg rquery -U '%v' "$1" 2>/dev/null | head -1 || true
}

# Install or upgrade a FreeBSD/OPNsense repo package if the catalog is newer.
# Sets XRAY_PKG_SYNC_CHANGED=1 when install/upgrade from repo succeeded.
xray_pkg_sync_repo() {
    _name="$1"
    XRAY_PKG_SYNC_CHANGED=0
    echo "==> Checking $_name (pkg repository) ..."
    _avail=$(xray_pkg_repo_version "$_name")
    if pkg info -e "$_name" >/dev/null 2>&1; then
        _inst=$(pkg query '%v' "$_name" 2>/dev/null || echo '')
        if [ -z "$_avail" ]; then
            echo "[OK]  $_name: ${_inst:-installed} (not in catalog — skip)"
            return 0
        fi
        if xray_pkg_is_older "$_inst" "$_avail"; then
            echo "[INFO] $_name $_inst → $_avail (repo)"
            if pkg upgrade -Uy "$_name"; then
                echo "[OK]  $_name upgraded: $(pkg query '%v' "$_name" 2>/dev/null)"
                XRAY_PKG_SYNC_CHANGED=1
                return 0
            fi
            warn "$_name repo upgrade failed (keeping $_inst)"
            return 1
        fi
        echo "[OK]  $_name: $_inst (repo $_avail, up to date)"
        return 0
    fi
    if [ -z "$_avail" ]; then
        warn "$_name not installed and not in pkg catalog"
        return 1
    fi
    echo "[INFO] installing $_name $_avail from repo..."
    if pkg install -Uy "$_name"; then
        echo "[OK]  $_name installed: $(pkg query '%v' "$_name" 2>/dev/null || echo installed)"
        XRAY_PKG_SYNC_CHANGED=1
        return 0
    fi
    warn "Failed to install $_name from pkg repository"
    return 1
}

xray_unbound_restart_if_needed() {
    if [ "${UB_WAS_RUNNING:-0}" = "1" ]; then
        if service unbound restart >/dev/null 2>&1; then
            echo "[OK]  unbound restarted"
        else
            warn "Could not restart unbound — restart DNS from GUI"
        fi
    fi
}

xray_unbound_install_github() {
    UB_PKG="/tmp/unbound-latest.pkg"
    echo "==> Installing unbound with DNSTAP from GitHub..."
    if ! fetch -o "$UB_PKG" "$UNBOUND_DNSTAP_PKG_URL"; then
        warn "Failed to fetch $UNBOUND_DNSTAP_PKG_URL"
        BINARIES_OK=0
        rm -f "$UB_PKG"
        return 1
    fi
    if pkg add -f "$UB_PKG"; then
        rm -f "$UB_PKG"
        if xray_unbound_has_dnstap; then
            _ub="$(xray_unbound_bin)"
            echo "[OK]  unbound (GitHub) $("$_ub" -V 2>&1 | awk 'NR==1{print; exit}')"
        else
            warn "GitHub unbound installed, but --enable-dnstap still not in unbound -V"
            BINARIES_OK=0
            return 1
        fi
        if pkg lock -y unbound; then
            echo "[OK]  pkg lock unbound (GitHub DNSTAP — OPNsense/pkg will not overwrite)"
        else
            warn "pkg lock unbound failed — OPNsense upgrade may replace this GitHub build"
        fi
        xray_unbound_restart_if_needed
        return 0
    fi
    warn "Failed to install unbound from $UNBOUND_DNSTAP_PKG_URL"
    BINARIES_OK=0
    rm -f "$UB_PKG"
    return 1
}

xray_ensure_bird2() {
    if xray_pkg_sync_repo bird2; then
        :
    else
        BINARIES_OK=0
    fi
}

# Unbound: repo first (if catalog is newer), then DNSTAP check; GitHub + lock only if no DNSTAP.
xray_ensure_unbound_dnstap() {
    echo "==> Checking unbound ..."
    UB_WAS_RUNNING=0
    if service unbound onestatus >/dev/null 2>&1; then
        UB_WAS_RUNNING=1
    fi
    if xray_pkg_sync_repo unbound; then
        if [ "${XRAY_PKG_SYNC_CHANGED:-0}" = "1" ]; then
            xray_unbound_restart_if_needed
        fi
    elif ! pkg info -e unbound >/dev/null 2>&1; then
        BINARIES_OK=0
    fi
    if xray_unbound_has_dnstap; then
        _ub="$(xray_unbound_bin)"
        echo "[OK]  unbound has --enable-dnstap ($("$_ub" -V 2>&1 | awk 'NR==1{print; exit}'))"
        echo "[OK]  unbound not pkg-locked (lock only after GitHub DNSTAP install)"
        return 0
    fi
    warn "unbound has no --enable-dnstap — replacing with GitHub build"
    xray_unbound_install_github || true
}

xray_ensure_os_dnstap_bgp() {
    echo "==> Checking os-dnstap-bgp from https://github.com/${DTAP_GH_REPO} ..."
    DTAP_FRESH_INSTALL=0
    DTAP_PKG="/tmp/os-dnstap-bgp.pkg"
    if ! fetch -o "$DTAP_PKG" "$DTAP_PKG_URL" 2>/dev/null; then
        warn "Failed to fetch os-dnstap-bgp from $DTAP_PKG_URL"
        if ! pkg info -e os-dnstap-bgp >/dev/null 2>&1; then
            BINARIES_OK=0
        fi
        rm -f "$DTAP_PKG"
        return 0
    fi
    _new=$(xray_pkg_file_version "$DTAP_PKG")
    if pkg info -e os-dnstap-bgp >/dev/null 2>&1; then
        _inst=$(pkg query '%v' os-dnstap-bgp 2>/dev/null || echo '')
        if [ -z "$_new" ]; then
            echo "[OK]  os-dnstap-bgp: ${_inst:-installed} (could not read GitHub pkg version, configs unchanged)"
            rm -f "$DTAP_PKG"
            return 0
        fi
        if [ -n "$_new" ] && xray_pkg_is_older "$_inst" "$_new"; then
            echo "[INFO] os-dnstap-bgp $_inst → $_new (preserving configs)"
            _dtap_bak="/tmp/os-xray-dnstap-cfg-bak"
            rm -rf "$_dtap_bak"
            mkdir -p "$_dtap_bak"
            [ -d /usr/local/etc/dnstap-bgp ] && cp -a /usr/local/etc/dnstap-bgp "$_dtap_bak/"
            [ -e /usr/local/etc/rc.conf.d/dnstap_bgp ] && cp -a /usr/local/etc/rc.conf.d/dnstap_bgp "$_dtap_bak/"
            if pkg add -f "$DTAP_PKG"; then
                echo "[OK]  os-dnstap-bgp upgraded: $(pkg query '%v' os-dnstap-bgp 2>/dev/null)"
                [ -d "$_dtap_bak/dnstap-bgp" ] && rm -rf /usr/local/etc/dnstap-bgp && cp -a "$_dtap_bak/dnstap-bgp" /usr/local/etc/dnstap-bgp
                [ -e "$_dtap_bak/dnstap_bgp" ] && cp -a "$_dtap_bak/dnstap_bgp" /usr/local/etc/rc.conf.d/dnstap_bgp
                echo "[OK]  restored dnstap-bgp configuration"
            else
                warn "os-dnstap-bgp upgrade failed (keeping $_inst)"
            fi
            rm -rf "$_dtap_bak"
        else
            echo "[OK]  os-dnstap-bgp: ${_inst:-installed} (up to date, configs unchanged)"
        fi
    else
        if pkg add "$DTAP_PKG"; then
            echo "[OK]  os-dnstap-bgp installed: $(pkg query '%v' os-dnstap-bgp 2>/dev/null || echo installed)"
            DTAP_FRESH_INSTALL=1
        else
            warn "Failed to install os-dnstap-bgp. Install manually from https://github.com/${DTAP_GH_REPO}/releases"
            BINARIES_OK=0
        fi
    fi
    rm -f "$DTAP_PKG"
}

# Remove packages that were not on the system before first install,
# including their configs and startup scripts (rc.d / rc.conf / rc.conf.d).
xray_uninstall_managed_packages() {
    echo "==> Removing packages installed by os-xray (not present before first install)..."
    if [ ! -f "$XRAY_PKG_BASELINE" ]; then
        warn "No $XRAY_PKG_BASELINE — not deleting bird2/unbound/os-dnstap-bgp/tun2socks (unknown pre-install state)"
        return 0
    fi
    if ! xray_pkg_was_preinstalled os-dnstap-bgp; then
        if pkg info -e os-dnstap-bgp >/dev/null 2>&1; then
            service dnstap_bgp onestop >/dev/null 2>&1 || true
            pkg delete -y os-dnstap-bgp || true
            echo "[OK]  removed os-dnstap-bgp"
        fi
        rm -rf /usr/local/etc/dnstap-bgp
        rm -f /usr/local/etc/rc.conf.d/dnstap_bgp /usr/local/etc/rc.conf.d/dnstap_bgp.sample
        rm -f /usr/local/etc/rc.d/dnstap_bgp /etc/rc.d/dnstap_bgp
        xray_rc_delete_var_all dnstap_bgp_enable
        xray_rc_delete_var_all dnstap_bgp_host_ip
        xray_rc_delete_var_all dnstap_bgp_jail_ip
        rm -f /usr/local/etc/unbound.opnsense.d/dnstap.conf
        rm -rf /var/log/dnstap-bgp /var/run/dnstap-bgp
        rm -f /var/run/dnstap_bgp.pid /var/run/dnstap-bgp.pid
        rm -rf /var/unbound/var/run/dnstap-bgp
        xray_remove_dnstap_log_configs
        echo "[OK]  removed dnstap-bgp configs and startup scripts"
    else
        echo "[OK]  os-dnstap-bgp was pre-installed — package, configs and rc scripts kept"
    fi
    if ! xray_pkg_was_preinstalled bird2; then
        if pkg info -e bird2 >/dev/null 2>&1; then
            service bird onestop >/dev/null 2>&1 || true
            pkg delete -y bird2 || true
            echo "[OK]  removed bird2"
        fi
        rm -f /usr/local/etc/bird.conf
        rm -rf /usr/local/etc/bird
        rm -f /usr/local/etc/rc.conf.d/bird /usr/local/etc/rc.conf.d/bird2
        rm -f /usr/local/etc/rc.d/bird /etc/rc.d/bird
        xray_rc_delete_var_all bird_enable
        rm -rf /var/log/bird /var/run/bird.ctl /var/run/bird.pid
        xray_remove_bird_log_configs
        echo "[OK]  removed bird2 configs and startup scripts"
    else
        echo "[OK]  bird2 was pre-installed — package, configs and rc scripts kept (plugin bird includes still removed)"
    fi
    if ! xray_pkg_was_preinstalled unbound; then
        if pkg info -e unbound >/dev/null 2>&1; then
            pkg unlock -y unbound >/dev/null 2>&1 || true
            service unbound onestop >/dev/null 2>&1 || true
            pkg delete -y unbound || true
            echo "[OK]  removed unbound"
        fi
        rm -rf /usr/local/etc/unbound
        rm -f /usr/local/etc/rc.conf.d/unbound
        rm -f /usr/local/etc/rc.d/unbound /etc/rc.d/unbound
        xray_rc_delete_var_all unbound_enable
        echo "[OK]  removed unbound configs and startup scripts (/var/unbound left for OPNsense)"
    else
        echo "[OK]  unbound was pre-installed — package, configs and rc scripts kept"
    fi
    if ! xray_pkg_was_preinstalled tun2socks; then
        rm -f "$T2S_BIN"
        rm -rf "$T2S_DIR"
        echo "[OK]  removed tun2socks binary and /usr/local/tun2socks"
    else
        echo "[OK]  tun2socks was pre-installed — binary kept"
    fi
    rm -f "$XRAY_PKG_BASELINE"
    rmdir "$(dirname "$XRAY_PKG_BASELINE")" 2>/dev/null || true
}

xray_restart_bird_log_services() {
    echo "==> Restarting syslog-ng and BIRD..."
    if service syslog-ng restart >/dev/null 2>&1; then
        echo "[OK]  syslog-ng restarted"
    else
        warn "syslog-ng restart failed"
    fi
    if service bird onestatus >/dev/null 2>&1; then
        if service bird onerestart >/dev/null 2>&1; then
            echo "[OK]  bird restarted"
        else
            warn "bird restart failed"
        fi
    else
        echo "[INFO] bird is not running, skip restart"
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# UNINSTALL / FULLUNINSTALL helpers
# ─────────────────────────────────────────────────────────────────────────────
xray_stop_plugin_services() {
    echo "==> Stopping services..."
    /usr/local/opnsense/scripts/Xray/xray-service-control.php stop 2>/dev/null || true
    service dnstap_bgp onestop >/dev/null 2>&1 || true
    service bird onestop >/dev/null 2>&1 || true
}

# Put WAN back as Upstream and ensure kernel default exists (UI active ≠ netstat).
xray_restore_wan_defaultgw() {
    local script="/usr/local/opnsense/scripts/Xray/xray-gateways.php"
    if [ ! -f "$script" ]; then
        echo "[INFO] gateways script missing — skip WAN defaultgw restore"
        return 0
    fi
    echo "==> Restoring WAN Upstream (defaultgw) + kernel default route..."
    if command -v php >/dev/null 2>&1; then
        php "$script" restore-wan-uninstall 2>&1 || warn "WAN defaultgw restore failed"
    elif [ -x /usr/local/bin/php ]; then
        /usr/local/bin/php "$script" restore-wan-uninstall 2>&1 || warn "WAN defaultgw restore failed"
    else
        warn "php not found — skip WAN defaultgw restore"
    fi
}

xray_uninstall_runtime_files() {
    echo "==> Cleaning runtime files..."
    rm -f /var/run/xray_core_*.pid /var/run/tun2socks_*.pid
    rm -f /var/run/xray_start_*.lock /var/run/xray_stopped_*.flag
    rm -f /var/run/xray_autoroute.last /var/run/xray_autoroute_state.json
    rm -f /var/run/xray_if_test.json /var/run/xray_active_tun_latency.json
    rm -f /var/run/xray_birdactivetun_status.json
    rm -f /usr/local/etc/xray-core/config-*.json
    rm -f /usr/local/tun2socks/config-*.yaml
    rm -f /var/run/xray_core.pid /var/run/tun2socks.pid
    rm -f /var/run/xray_start.lock /var/run/xray_stopped.flag
}

xray_uninstall_plugin_files() {
    echo "==> Removing plugin files..."
    rm -rf /usr/local/opnsense/scripts/Xray
    rm -f  /etc/newsyslog.conf.d/xray.conf
    rm -f  /usr/local/opnsense/service/conf/actions.d/actions_xray.conf
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/Xray
    rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray
    rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/Xray
    rm -rf /usr/local/opnsense/www/fonts/xray
    rm -f  /usr/local/etc/inc/plugins.inc.d/xray.inc
    rm -f  /usr/local/etc/rc.syshook.d/start/50-xray
    rm -f  /usr/local/etc/rc.syshook.d/start/10-xray-bird-hold
    rm -f  /usr/local/etc/rc.syshook.d/start/99-xray-bird
}

# Soft uninstall: only plugin-owned BIRD includes (leave bird.conf / other includes if bird kept).
xray_uninstall_bird_plugin_includes() {
    rm -f  /usr/local/etc/bird/bgp.conf
    rm -f  /usr/local/etc/bird/peers.inc
    rm -f  /usr/local/etc/bird/peer-*.inc
    rm -f  /usr/local/etc/bird/peer_*.inc
    rm -f  /usr/local/etc/bird/refilter.inc
    rm -f  /usr/local/etc/bird/antifilter_download.inc
    rm -f  /usr/local/etc/bird/antifilter_network.inc
    rm -f  /usr/local/etc/bird/ANTIFILTER_DOWNLOAD.inc
    rm -f  /usr/local/etc/bird/ANTIFILTER_NETWORK.inc
    rm -f  /usr/local/etc/bird/community_ANTIFILTER_DOWNLOAD.inc
    rm -f  /usr/local/etc/bird/community_ANTIFILTER_NETWORK.inc
    rm -f  /usr/local/etc/bird/community_DNSTAP_PROXY.inc
    rm -f  /usr/local/etc/bird/communities.inc
    rm -f  /usr/local/etc/bird/filters.inc
    rm -f  /usr/local/etc/bird/filter_*.inc
    rm -f  /usr/local/etc/bird/accept_*.inc
    rm -f  /usr/local/etc/bird/router_id.inc
    rm -f  /usr/local/etc/bird/.xray-bgp-generated
    rm -f  /usr/local/etc/bird/direct_ifs.inc
    rm -f  /usr/local/etc/bird/wan_if.inc
    rm -f  /usr/local/etc/bird/active_tun_v4.inc
    rm -f  /usr/local/etc/bird/active_tun_v6.inc
    rm -f  /usr/local/etc/bird/static_protocols.inc
    rm -f  /usr/local/etc/bird/routes_static_*.inc
    rm -f  /usr/local/etc/bird/static-*.json
    rm -rf /usr/local/etc/bird/static-cache
    rm -rf /usr/local/etc/bird/static-geodat-categories
}

xray_uninstall_finish_common() {
    echo "==> Restarting syslog-ng..."
    if service syslog-ng restart >/dev/null 2>&1; then
        echo "[OK]  syslog-ng restarted"
    else
        warn "syslog-ng restart failed"
    fi

    echo "==> Restarting configd..."
    service configd restart

    echo "==> Clearing cache..."
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
}

# fulluninstall: wipe configs/logs/startup + packages except unbound; never touch config.xml
xray_fulluninstall_all() {
    echo "==> fulluninstall: removing ALL os-xray configs, logs, startup scripts and packages"
    echo "    (keeping unbound package and /conf/config.xml)"

    # Logs (including rotations)
    echo "==> Removing logs..."
    rm -f /tmp/xray_syshook.log /tmp/xray_syshook.log.0
    rm -f /tmp/xray_autoroute.log /tmp/xray_autoroute.log.0
    rm -f /var/log/xray-core.log /var/log/xray-core.log.*
    rm -f /var/log/xray-boot.log /var/log/xray-boot.log.*
    rm -f /var/log/xray-autoroute.log /var/log/xray-autoroute.log.*
    rm -f /var/log/xray-watchdog.log /var/log/xray-watchdog.log.*
    rm -rf /var/log/bird
    rm -rf /var/log/dnstap-bgp

    # BIRD: package + all configs + rc
    echo "==> Removing bird2 and BIRD configs/startup..."
    service bird onestop >/dev/null 2>&1 || true
    if pkg info -e bird2 >/dev/null 2>&1; then
        pkg delete -y bird2 || true
        echo "[OK]  removed bird2 package"
    fi
    rm -f /usr/local/etc/bird.conf
    rm -rf /usr/local/etc/bird
    rm -f /usr/local/etc/rc.conf.d/bird /usr/local/etc/rc.conf.d/bird2
    rm -f /usr/local/etc/rc.d/bird /etc/rc.d/bird
    xray_rc_delete_var_all bird_enable
    rm -f /var/run/bird.ctl /var/run/bird.pid
    xray_remove_bird_log_configs
    echo "[OK]  removed BIRD configs and startup scripts"

    # dnstap-bgp: package + configs + rc (unbound package kept)
    echo "==> Removing os-dnstap-bgp and configs/startup (unbound package kept)..."
    service dnstap_bgp onestop >/dev/null 2>&1 || true
    if pkg info -e os-dnstap-bgp >/dev/null 2>&1; then
        pkg delete -y os-dnstap-bgp || true
        echo "[OK]  removed os-dnstap-bgp package"
    fi
    rm -rf /usr/local/etc/dnstap-bgp
    rm -f /usr/local/etc/rc.conf.d/dnstap_bgp /usr/local/etc/rc.conf.d/dnstap_bgp.sample
    rm -f /usr/local/etc/rc.d/dnstap_bgp /etc/rc.d/dnstap_bgp
    xray_rc_delete_var_all dnstap_bgp_enable
    xray_rc_delete_var_all dnstap_bgp_host_ip
    xray_rc_delete_var_all dnstap_bgp_jail_ip
    rm -f /usr/local/etc/unbound.opnsense.d/dnstap.conf
    rm -rf /var/run/dnstap-bgp /var/run/dnstap_bgp.pid /var/run/dnstap-bgp.pid
    rm -rf /var/unbound/var/run/dnstap-bgp
    xray_remove_dnstap_log_configs
    echo "[OK]  removed dnstap-bgp configs and startup scripts"
    echo "[OK]  unbound package left installed"

    # tun2socks + xray-core binaries and working dirs
    echo "==> Removing tun2socks and xray-core..."
    rm -f "$T2S_BIN"
    rm -rf "$T2S_DIR"
    rm -f /usr/local/bin/xray-core /usr/local/bin/xray
    rm -rf /usr/local/etc/xray-core
    echo "[OK]  removed tun2socks and xray-core"

    rm -f "$XRAY_PKG_BASELINE"
    rmdir "$(dirname "$XRAY_PKG_BASELINE")" 2>/dev/null || true
}

if [ "${1:-}" = "uninstall" ]; then
    # WAN Upstream + kernel default BEFORE stop (TUN may still hold default),
    # then again AFTER stop so netstat matches UI once TUN is gone.
    xray_restore_wan_defaultgw
    xray_stop_plugin_services
    xray_restore_wan_defaultgw
    xray_uninstall_runtime_files
    xray_uninstall_plugin_files

    # Soft uninstall keeps /var/log/xray-*.log for history
    rm -f /tmp/xray_syshook.log /tmp/xray_syshook.log.0
    rm -f /tmp/xray_autoroute.log /tmp/xray_autoroute.log.0

    xray_uninstall_bird_plugin_includes

    echo "==> Removing BIRD / dnstap-bgp syslog-ng / newsyslog configs..."
    xray_remove_bird_log_configs
    xray_remove_dnstap_log_configs

    xray_uninstall_managed_packages

    # Only syslog-ng: bird includes were just removed; if bird2 was pre-installed and
    # kept running, leave the process alone — operator can fix/reload bird.conf later.
    if service bird onestatus >/dev/null 2>&1; then
        echo "[INFO] bird still running (plugin includes removed) — not restarting"
    fi

    xray_uninstall_finish_common

    echo ""
    echo "=============================="
    echo "  os-xray plugin removed."
    echo "=============================="
    echo "Refresh browser with Ctrl+F5."
    echo "For a full wipe (configs/logs/packages except unbound):"
    echo "  sh install.sh fulluninstall"
    exit 0
fi

if [ "${1:-}" = "fulluninstall" ]; then
    xray_restore_wan_defaultgw
    xray_stop_plugin_services
    xray_restore_wan_defaultgw
    xray_uninstall_runtime_files
    xray_uninstall_plugin_files
    xray_fulluninstall_all
    xray_uninstall_finish_common

    echo ""
    echo "========================================"
    echo "  os-xray fulluninstall complete."
    echo "========================================"
    echo "Removed: plugin, bird2, os-dnstap-bgp, tun2socks, xray-core,"
    echo "         BIRD/dnstap configs, logs, startup scripts."
    echo "Kept:    unbound package, /conf/config.xml"
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
    # v2.0.0: per-instance configs (config-<uuid>.json), fallback to old single config.json
    XRAY_JSON=""
    for _F in /usr/local/etc/xray-core/config-*.json /usr/local/etc/xray-core/config.json; do
        [ -f "$_F" ] && XRAY_JSON="$_F" && break
    done
    XRAY_JSON="${XRAY_JSON:-/usr/local/etc/xray-core/config.json}"

    T2S_YAML=""
    for _F in /usr/local/tun2socks/config-*.yaml /usr/local/tun2socks/config.yaml; do
        [ -f "$_F" ] && T2S_YAML="$_F" && break
    done
    T2S_YAML="${T2S_YAML:-/usr/local/tun2socks/config.yaml}"

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

# Remove empty placeholder instances (name "default"/empty, no usable server/outbound).
# Safe when other real instances exist. Returns via stdout: OK:N | SKIP | ERR:...
xray_purge_placeholder_instances() {
    php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

function xray_inst_has_endpoint($inst): bool
{
    $server = trim((string)($inst->server_address ?? ''));
    $vless  = trim((string)($inst->vless_uuid ?? ''));
    if ($server !== '' || $vless !== '') {
        return true;
    }
    foreach (['outbound_config', 'custom_config'] as $field) {
        $raw = trim((string)($inst->{$field} ?? ''));
        if ($raw === '' || $raw === '{}' || $raw === 'null' || $raw === '[]') {
            continue;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            // Non-JSON blob — treat as configured
            return true;
        }
        $addr = $j['settings']['vnext'][0]['address']
            ?? $j['settings']['servers'][0]['address']
            ?? '';
        if (trim((string)$addr) !== '') {
            return true;
        }
        // Outbound without address (broken/placeholder JSON) → not an endpoint
    }
    return false;
}

try {
    $cfg = OPNsense\Core\Config::getInstance();
    $obj = $cfg->object();
    $instances = $obj->OPNsense->xray->instances ?? null;
    if (!$instances || !isset($instances->instance)) {
        echo "SKIP";
        exit(0);
    }
    $toRemove = [];
    foreach ($instances->instance as $inst) {
        $name = trim((string)($inst->name ?? ''));
        $isDefaultName = ($name === '' || strcasecmp($name, 'default') === 0);
        if ($isDefaultName && !xray_inst_has_endpoint($inst)) {
            $toRemove[] = $inst;
        }
    }
    if (empty($toRemove)) {
        echo "SKIP";
        exit(0);
    }
    $removed = 0;
    foreach ($toRemove as $inst) {
        $dom = dom_import_simplexml($inst);
        if ($dom && $dom->parentNode) {
            $dom->parentNode->removeChild($dom);
            $removed++;
        }
    }
    if ($removed === 0) {
        echo "SKIP";
        exit(0);
    }
    $cfg->save();
    echo "OK:$removed";
} catch (Throwable $e) {
    echo "ERR:" . $e->getMessage();
}
PHPEOF
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

// v2.0.0: ArrayField — instances (plural) с instance (child) с uuid-атрибутом
if (!isset($x->instances)) {
    $x->addChild('instances');
}
// Never add a second instance on reinstall if GUI already has any
foreach ($x->instances->instance ?? [] as $_exist) {
    if ((string)($_exist['uuid'] ?? '') !== '') {
        echo "SKIP: instances already present — not importing file config\n";
        exit(0);
    }
}
$inst = $x->instances->addChild('instance');
// Генерируем UUID для OPNsense BaseModel (атрибут инстанса, не VLESS UUID)
$instUuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);
$inst->addAttribute('uuid', $instUuid);

$x->general->enabled    = '1';
// Defaults: BGP / DNStap BGP / Auto route off; Routing strategy = System policy
foreach ([
    'bgp_enabled' => '0',
    'dnstap_bgp_enabled' => '0',
    'bgp_auto_route' => '0',
    'routing_strategy' => 'system_policy',
] as $_gk => $_gv) {
    if (!isset($x->general->{$_gk})) {
        $x->general->addChild($_gk, $_gv);
    } else {
        $x->general->{$_gk} = $_gv;
    }
}
$importName = trim((string)($d['server'] ?? ''));
if ($importName === '') {
    $importName = 'imported';
}
$inst->addChild('name',                $importName);
$inst->addChild('server_address',      $d['server']);
$inst->addChild('server_port',         (string)$d['port']);
$inst->addChild('vless_uuid',          $d['uuid']);
$inst->addChild('flow',                $d['flow']);
$inst->addChild('reality_sni',         $d['sni']);
$inst->addChild('reality_pubkey',      $d['pubkey']);
$inst->addChild('reality_shortid',     $d['shortid']);
$inst->addChild('reality_fingerprint', $d['fp']);
$inst->addChild('socks5_port',         (string)$d['socks5']);
$inst->addChild('tun_interface',       $d['tun']);
$inst->addChild('mtu',                 (string)$d['mtu']);
$inst->addChild('loglevel',            'warning');
$inst->addChild('config_mode',         'wizard');

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
# INSTALL HELPERS
# ─────────────────────────────────────────────────────────────────────────────

# install_tree <src_dir> <dst_dir> <file_mode>
# Recursive installation of each file from <src_dir> to <dst_dir> with <file_mode> modifier
install_tree() {
    _src="$1"
    _dst="$2"
    _fmode="${3:-0644}"

    find "$_src" -mindepth 1 -type d | while IFS= read -r _dir; do
        _rel="${_dir#$_src}"
        install -d "${_dst}${_rel}"
    done
    find "$_src" -type f | while IFS= read -r _file; do
        _rel="${_file#$_src}"
        install -m "$_fmode" "$_file" "${_dst}${_rel}"
    done
}

# ─────────────────────────────────────────────────────────────────────────────
# INSTALL
# ─────────────────────────────────────────────────────────────────────────────

# ─────────────────────────────────────────────────────────────────────────────
# VERSION CHECK & CONFIRMATION
# ─────────────────────────────────────────────────────────────────────────────
CURRENT_VERSION="not installed"
if [ -f "$VERSION_FILE" ]; then
    CURRENT_VERSION=$(cat "$VERSION_FILE" 2>/dev/null || echo "unknown")
fi

echo "============================================================"
echo "  os-xray plugin installer"
echo "============================================================"
echo ""
echo "  Current version : ${CURRENT_VERSION}"
echo "  New version     : ${PLUGIN_VERSION}"
echo ""

if [ "$CURRENT_VERSION" = "$PLUGIN_VERSION" ]; then
    echo "  Version ${PLUGIN_VERSION} is already installed."
    printf "  Reinstall? [y/N] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="n"
    case "$_CONFIRM" in
        [yY]*) ;;
        *) echo "  Installation cancelled."; exit 0 ;;
    esac
elif [ "$CURRENT_VERSION" != "not installed" ]; then
    printf "  Upgrade from ${CURRENT_VERSION} to ${PLUGIN_VERSION}? [Y/n] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="y"
    case "$_CONFIRM" in
        [nN]*) echo "  Installation cancelled."; exit 0 ;;
        *) ;;
    esac
else
    printf "  Install version ${PLUGIN_VERSION}? [Y/n] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="y"
    case "$_CONFIRM" in
        [nN]*) echo "  Installation cancelled."; exit 0 ;;
        *) ;;
    esac
fi

echo ""

XRAY_KEEP_CONFIG=0
if [ "$CURRENT_VERSION" != "not installed" ]; then
    XRAY_KEEP_CONFIG=1
    echo "[INFO] Reinstall/upgrade: packages and startup scripts will be updated; live configs kept"
fi

echo "==> Recording package baseline (first install only)..."
xray_pkg_baseline_init

# ── Шаг 1: Проверка бинарников ───────────────────────────────────────────────
echo "==> Step 1: Checking binaries..."
BINARIES_OK=1

XRAY_NEEDS_INSTALL=0
XRAY_NEEDS_UPGRADE=0

if [ ! -f /usr/local/bin/xray-core ]; then
    warn "xray-core NOT found at /usr/local/bin/xray-core"
    BINARIES_OK=0
    XRAY_NEEDS_INSTALL=1
else
    XRAY_VER=$(/usr/local/bin/xray-core version 2>/dev/null | head -1 || echo 'unknown')
    echo "[OK]  xray-core: $XRAY_VER"
    # P2.5: xray-core 1.x не поддерживает xhttp+Reality (Custom Config).
    # Рекомендуем 24.x+ для полной совместимости со всеми протоколами.
    case "$XRAY_VER" in
        *" 1."*)
            echo ""
            warn "xray-core 1.x detected. Version 24.x+ is recommended."
            warn "Custom Config (xhttp, splithttp+Reality) requires 24.x+."
            XRAY_NEEDS_UPGRADE=1
            ;;
    esac
fi

# Предложить установку или обновление xray-core
if [ "$XRAY_NEEDS_INSTALL" = "1" ] || [ "$XRAY_NEEDS_UPGRADE" = "1" ]; then
    echo ""
    if [ "$XRAY_NEEDS_INSTALL" = "1" ]; then
        printf "  Download and install xray-core (latest)? [Y/n] "
    else
        printf "  Upgrade xray-core to latest version? [Y/n] "
    fi
    read -r _XRAY_CONFIRM < /dev/tty 2>/dev/null || _XRAY_CONFIRM="y"
    case "$_XRAY_CONFIRM" in
        [nN]*)
            if [ "$XRAY_NEEDS_INSTALL" = "1" ]; then
                echo "  Skipped. Install manually:"
                echo "    fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip"
                echo "    cd /tmp && unzip xray.zip xray && install -m 0755 xray /usr/local/bin/xray-core"
            else
                echo "  Skipped. Upgrade manually when ready."
            fi
            ;;
        *)
            echo "  Downloading latest xray-core..."
            if fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip 2>/dev/null; then
                # Останавливаем xray если запущен (перед заменой бинарника)
                # v2.0.0: per-instance PID files (xray_core_*.pid)
                for _PIDFILE in /var/run/xray_core_*.pid /var/run/xray_core.pid; do
                    [ -f "$_PIDFILE" ] || continue
                    _PID=$(cat "$_PIDFILE" 2>/dev/null || echo "0")
                    if kill -0 "$_PID" 2>/dev/null; then
                        echo "  Stopping running xray-core (PID $_PID)..."
                        kill "$_PID" 2>/dev/null || true
                    fi
                done
                sleep 1
                cd /tmp && unzip -o xray.zip xray 2>/dev/null && install -m 0755 /tmp/xray /usr/local/bin/xray-core
                rm -f /tmp/xray.zip /tmp/xray
                XRAY_VER=$(/usr/local/bin/xray-core version 2>/dev/null | head -1 || echo 'unknown')
                echo "  [OK]  xray-core updated: $XRAY_VER"
                BINARIES_OK=1
                XRAY_NEEDS_INSTALL=0
                XRAY_NEEDS_UPGRADE=0
            else
                warn "Download failed. Check internet connection."
                warn "Install manually: fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip"
            fi
            ;;
    esac
fi

# bird2 — пакет FreeBSD/OPNsense (pkg) + конфиг из git
BIRD_CONF_SRC="$(dirname "$0")/bird.conf"
BIRD_CONF_DST="/usr/local/etc/bird.conf"
BIRD_CONF_URL="https://raw.githubusercontent.com/${REPO_OWNER}/${REPO_NAME}/${REPO_BRANCH}/bird.conf"
BGP_CONF_SRC="$(dirname "$0")/bgp.conf"
BGP_CONF_DST="/usr/local/etc/bird/bgp.conf"
BGP_CONF_URL="https://raw.githubusercontent.com/${REPO_OWNER}/${REPO_NAME}/${REPO_BRANCH}/bgp.conf"
BIRD_INC_DIR="/usr/local/etc/bird"

xray_unbound_unlock
xray_pkg_gui_update_notice
xray_ensure_tun2socks
xray_ensure_bird2
xray_ensure_unbound_dnstap
DTAP_FRESH_INSTALL=0
xray_ensure_os_dnstap_bgp

if [ "$DTAP_FRESH_INSTALL" = "1" ]; then
    echo "==> Copying dnstap-bgp sample configs (first install only)..."
    xray_copy_dnstap_bgp_samples
fi
echo "==> Migrating DNStap files to PROXY/DIRECT/BLOCK (dnstap-bgp 1.5.x)..."
xray_migrate_dnstap_pkg
echo "==> Seeding DNStap PROXY list URL..."
xray_seed_dnstap_proxy_url
echo "==> Enabling DNStap IPv6 by default..."
xray_seed_dnstap_ipv6_default

echo "==> Creating BIRD log directory and syslog-ng / newsyslog configs..."
xray_write_bird_log_configs
echo "[OK]  /var/log/bird  /usr/local/etc/syslog-ng.conf.d/bird.conf  /usr/local/etc/newsyslog.conf.d/bird.conf"

echo "==> Creating dnstap-bgp log directory and syslog-ng / newsyslog configs..."
xray_write_dnstap_log_configs
echo "[OK]  /var/log/dnstap-bgp  /usr/local/etc/syslog-ng.conf.d/dnstap-bgp.conf  /usr/local/etc/newsyslog.conf.d/dnstap-bgp.conf"

echo "==> Installing bird.conf from git (${REPO_BRANCH})..."
if xray_skip_existing_config "$BIRD_CONF_DST"; then
    :
elif [ -f "$BIRD_CONF_SRC" ]; then
    install -m 0644 "$BIRD_CONF_SRC" "$BIRD_CONF_DST"
    echo "[OK]  $BIRD_CONF_DST (from local clone)"
elif fetch -o "$BIRD_CONF_DST" "$BIRD_CONF_URL" 2>/dev/null; then
    chmod 0644 "$BIRD_CONF_DST"
    echo "[OK]  $BIRD_CONF_DST (from $BIRD_CONF_URL)"
else
    warn "Failed to install bird.conf from git. Place it at $BIRD_CONF_DST manually."
fi
if ! xray_keep_config && [ -f "$BIRD_CONF_DST" ]; then
    if grep -q '^log syslog' "$BIRD_CONF_DST"; then
        sed -i '' -e 's/^log syslog.*/log syslog { warning };/' "$BIRD_CONF_DST"
    else
        printf '%s\n' 'log syslog { warning };' | cat - "$BIRD_CONF_DST" > "${BIRD_CONF_DST}.tmp" \
            && mv "${BIRD_CONF_DST}.tmp" "$BIRD_CONF_DST"
    fi
    echo "[OK]  log syslog { warning }; in $BIRD_CONF_DST"
fi

echo "==> Creating $BIRD_INC_DIR includes..."
install -d -m 0755 "$BIRD_INC_DIR"

echo "==> Installing bgp.conf template (${REPO_BRANCH})..."
if xray_skip_existing_config "$BGP_CONF_DST"; then
    :
elif [ -f "$BGP_CONF_SRC" ]; then
    install -m 0644 "$BGP_CONF_SRC" "$BGP_CONF_DST"
    echo "[OK]  $BGP_CONF_DST (from local clone)"
elif fetch -o "$BGP_CONF_DST" "$BGP_CONF_URL" 2>/dev/null; then
    chmod 0644 "$BGP_CONF_DST"
    echo "[OK]  $BGP_CONF_DST (from $BGP_CONF_URL)"
else
    warn "Failed to install bgp.conf template. Place it at $BGP_CONF_DST manually."
fi

echo "==> Installing BIRD includes (${REPO_BRANCH})..."
BGP_PEERS_SRC="$(dirname "$0")/bird"

# Copy one include: try local names then GitHub names (case differs on Windows git).
install_bird_inc() {
    _DST_BASE="$1"
    shift
    _DST="${BIRD_INC_DIR}/${_DST_BASE}.inc"
    if xray_skip_existing_config "$_DST"; then
        return 0
    fi
    for _TRY in "$_DST_BASE" "$@"; do
        [ -n "$_TRY" ] || continue
        _SRC="${BGP_PEERS_SRC}/${_TRY}.inc"
        _URL="https://raw.githubusercontent.com/${REPO_OWNER}/${REPO_NAME}/${REPO_BRANCH}/bird/${_TRY}.inc"
        if [ -f "$_SRC" ]; then
            install -m 0644 "$_SRC" "$_DST"
            echo "[OK]  $_DST (from local clone ${_TRY}.inc)"
            return 0
        fi
        if fetch -o "$_DST" "$_URL" 2>/dev/null; then
            chmod 0644 "$_DST"
            echo "[OK]  $_DST (from $_URL)"
            return 0
        fi
    done
    return 1
}

for _PEER in filters router_id communities; do
    if ! install_bird_inc "$_PEER"; then
        warn "Failed to install ${_PEER}.inc"
    fi
done

echo "[INFO] peer/filter/community .inc files are generated from config.xml after seed."
if xray_keep_config; then
    echo "[SKIP] not removing existing BIRD include files (reinstall — keep configuration)"
else
rm -f "$BIRD_INC_DIR/refilter.inc" \
      "$BIRD_INC_DIR/antifilter_download.inc" \
      "$BIRD_INC_DIR/antifilter_network.inc" \
      "$BIRD_INC_DIR/accept_refilter.inc" \
      "$BIRD_INC_DIR/accept_antifilter_download.inc" \
      "$BIRD_INC_DIR/accept_antifilter_network_v4.inc" \
      "$BIRD_INC_DIR/accept_antifilter_network_v6.inc" \
      "$BIRD_INC_DIR/filter_accept_refilter.inc" \
      "$BIRD_INC_DIR/filter_accept_antifilter_download.inc" \
      "$BIRD_INC_DIR/filter_accept_antifilter_network_v4.inc" \
      "$BIRD_INC_DIR/filter_accept_antifilter_network_v6.inc"
fi

if [ ! -f "$BIRD_INC_DIR/communities.inc" ]; then
    printf '%s\n' '# generated by os-xray — BGP community defines from config.xml' \
        > "$BIRD_INC_DIR/communities.inc"
    chmod 0644 "$BIRD_INC_DIR/communities.inc"
    echo "[OK]  $BIRD_INC_DIR/communities.inc (empty stub)"
fi

# Detect WAN devices for first-install stubs (no hardcoded igc3)
_WAN4_DET="$(xray_detect_wan_if || true)"
_WAN6_DET="$(/sbin/route -n get -inet6 default 2>/dev/null | awk '/interface:/{print $2; exit}')"
[ -z "$_WAN6_DET" ] && _WAN6_DET="$_WAN4_DET"
# Shell-safe quoted names for bird stubs; empty → omit until PHP seed
_WAN4_Q="${_WAN4_DET:-}"
_WAN6_Q="${_WAN6_DET:-}"

if [ ! -f "$BIRD_INC_DIR/direct_ifs.inc" ]; then
    _DIRS='"lo0", "lo"'
    [ -n "$_WAN4_Q" ] && _DIRS="\"${_WAN4_Q}\", ${_DIRS}"
    if [ -n "$_WAN6_Q" ] && [ "$_WAN6_Q" != "$_WAN4_Q" ]; then
        _DIRS="\"${_WAN6_Q}\", ${_DIRS}"
    fi
    printf '%s\n' '# generated by os-xray — protocol direct interfaces (WAN4/WAN6, lo, tun2socks)' \
        "interface ${_DIRS};" > "$BIRD_INC_DIR/direct_ifs.inc"
    chmod 0644 "$BIRD_INC_DIR/direct_ifs.inc"
    echo "[OK]  $BIRD_INC_DIR/direct_ifs.inc (wan4=${_WAN4_Q:-none} wan6=${_WAN6_Q:-none})"
else
    echo "[SKIP] $BIRD_INC_DIR/direct_ifs.inc already exists"
fi

if [ ! -f "$BIRD_INC_DIR/wan_if.inc" ]; then
    {
        echo '# generated by os-xray — WAN4/WAN6 devices (General tab)'
        if [ -n "$_WAN4_Q" ]; then
            echo "define WAN4_IF = \"${_WAN4_Q}\";"
            echo "define WAN_IF = \"${_WAN4_Q}\";"
        else
            echo '# WAN4_IF: not detected yet — filled on first-install seed'
        fi
        if [ -n "$_WAN6_Q" ]; then
            echo "define WAN6_IF = \"${_WAN6_Q}\";"
        else
            echo '# WAN6_IF: not detected yet — filled on first-install seed'
        fi
        echo '# IPv4_GATEWAY / IPv6_GATEWAY filled by os-xray from WAN default route'
    } > "$BIRD_INC_DIR/wan_if.inc"
    chmod 0644 "$BIRD_INC_DIR/wan_if.inc"
    echo "[OK]  $BIRD_INC_DIR/wan_if.inc (wan4=${_WAN4_Q:-none} wan6=${_WAN6_Q:-none})"
else
    echo "[SKIP] $BIRD_INC_DIR/wan_if.inc already exists"
fi

if [ ! -f "$BIRD_INC_DIR/active_tun_v4.inc" ]; then
    printf '%s\n' 'define ACTIVE_TUN4_IF = "proxytun2socks0";' > "$BIRD_INC_DIR/active_tun_v4.inc"
    chmod 0644 "$BIRD_INC_DIR/active_tun_v4.inc"
    echo "[OK]  $BIRD_INC_DIR/active_tun_v4.inc"
else
    echo "[SKIP] $BIRD_INC_DIR/active_tun_v4.inc already exists"
fi
if [ ! -f "$BIRD_INC_DIR/active_tun_v6.inc" ]; then
    printf '%s\n' 'define ACTIVE_TUN6_IF = "proxytun2socks0";' > "$BIRD_INC_DIR/active_tun_v6.inc"
    chmod 0644 "$BIRD_INC_DIR/active_tun_v6.inc"
    echo "[OK]  $BIRD_INC_DIR/active_tun_v6.inc"
else
    echo "[SKIP] $BIRD_INC_DIR/active_tun_v6.inc already exists"
fi

for _static_inc in \
    routes_static_v4_PROXY.inc routes_static_v4_DIRECT.inc routes_static_v4_BLOCK.inc \
    routes_static_v6_PROXY.inc routes_static_v6_DIRECT.inc routes_static_v6_BLOCK.inc \
    routes_static_v4_PROXY_geodat.inc routes_static_v4_DIRECT_geodat.inc routes_static_v4_BLOCK_geodat.inc \
    routes_static_v6_PROXY_geodat.inc routes_static_v6_DIRECT_geodat.inc routes_static_v6_BLOCK_geodat.inc \
    routes_static_v4_apply.inc routes_static_v6_apply.inc
do
    if [ ! -f "$BIRD_INC_DIR/$_static_inc" ]; then
        printf '%s\n' '#' > "$BIRD_INC_DIR/$_static_inc"
        chmod 0644 "$BIRD_INC_DIR/$_static_inc"
        echo "[OK]  $BIRD_INC_DIR/$_static_inc"
    else
        echo "[SKIP] $BIRD_INC_DIR/$_static_inc already exists"
    fi
done
for _static_json in \
    static-proxy-urls.json static-direct-urls.json static-block-urls.json static-geodat-urls.json
do
    if [ ! -f "$BIRD_INC_DIR/$_static_json" ]; then
        printf '%s\n' '[]' > "$BIRD_INC_DIR/$_static_json"
        chmod 0644 "$BIRD_INC_DIR/$_static_json"
        echo "[OK]  $BIRD_INC_DIR/$_static_json"
    else
        echo "[SKIP] $BIRD_INC_DIR/$_static_json already exists"
    fi
done
# static_protocols.inc: empty until Enable static route (protocols live only when enabled)
if [ ! -f "$BIRD_INC_DIR/static_protocols.inc" ]; then
    printf '%s\n' '# static routes disabled (Enable static route on Static tab)' \
        > "$BIRD_INC_DIR/static_protocols.inc"
    chmod 0644 "$BIRD_INC_DIR/static_protocols.inc"
    echo "[OK]  $BIRD_INC_DIR/static_protocols.inc"
else
    echo "[SKIP] $BIRD_INC_DIR/static_protocols.inc already exists"
fi
install -d -m 0755 "$BIRD_INC_DIR/static-geodat-categories"
install -d -m 0755 "$BIRD_INC_DIR/static-cache/proxy"
install -d -m 0755 "$BIRD_INC_DIR/static-cache/direct"
install -d -m 0755 "$BIRD_INC_DIR/static-cache/block"
install -d -m 0755 "$BIRD_INC_DIR/static-cache/geodat"


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
# v2.0.0: per-instance PID files
_XRAY_RUNNING=0
for _PIDFILE in /var/run/xray_core_*.pid /var/run/xray_core.pid; do
    [ -f "$_PIDFILE" ] || continue
    _XRAY_PID=$(cat "$_PIDFILE" 2>/dev/null || echo "0")
    if kill -0 "$_XRAY_PID" 2>/dev/null; then
        _XRAY_RUNNING=1
        break
    fi
done

if [ "$_XRAY_RUNNING" = "1" ]; then
    echo "[SKIP] xray-core is running — port ${_CHECK_PORT} is held by xray itself."
elif check_port "$_CHECK_PORT"; then
    echo "[OK]  Port ${_CHECK_PORT} is available."
fi
# check_port при занятом порте уже вывел warn — установка продолжается

# ── Шаг 3: Установка файлов плагина ──────────────────────────────────────────
echo ""
echo "==> Step 3: Installing plugin files..."

# PHP-scripts (executable)
install -d /usr/local/opnsense/scripts/Xray
install_tree "$PLUGIN_DIR/scripts/Xray" \
             "/usr/local/opnsense/scripts/Xray" 0755

echo "==> Seeding DNStap default geodat categories..."
xray_seed_dnstap_geodat_categories
echo "==> Seeding Static default geodat URL/categories..."
xray_seed_static_geodat_defaults

# configd actions
install -d /usr/local/opnsense/service/conf/actions.d
install_tree "$PLUGIN_DIR/service/conf/actions.d" \
             "/usr/local/opnsense/service/conf/actions.d" 0644

# MVC: models, controllers, views (including subdirectories — partials и т.д.)
install -d /usr/local/opnsense/mvc/app/models/OPNsense/Xray
install_tree "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray" \
             "/usr/local/opnsense/mvc/app/models/OPNsense/Xray" 0644

install -d /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray
install_tree "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray" \
             "/usr/local/opnsense/mvc/app/controllers/OPNsense/Xray" 0644

install -d /usr/local/opnsense/mvc/app/views/OPNsense/Xray
install_tree "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray" \
             "/usr/local/opnsense/mvc/app/views/OPNsense/Xray" 0644

# Static assets (flag emoji polyfill for desktop Chrome/Windows)
install -d /usr/local/opnsense/www/fonts/xray
if [ -d "$PLUGIN_DIR/www/fonts/xray" ]; then
    install_tree "$PLUGIN_DIR/www/fonts/xray" \
                 "/usr/local/opnsense/www/fonts/xray" 0644
fi

# System hooks
install -d /usr/local/etc/inc/plugins.inc.d
install_tree "$PLUGIN_DIR/etc/inc/plugins.inc.d" \
             "/usr/local/etc/inc/plugins.inc.d" 0644

# Register plugin autocron jobs in /var/cron/tabs/root (watchdog, IF health, Auto route, …)
echo "==> Refreshing system crontab (xray autocron)..."
/usr/local/bin/php <<'PHPEOF' 2>&1 || echo "[WARN] crontab refresh failed — reboot or save System → Settings → Cron once"
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once 'config.inc';
require_once 'util.inc';
require_once 'plugins.inc';
require_once 'system.inc';
// CLI bootstrap may omit helpers that system_cron_configure() expects.
if (!function_exists('service_log')) {
    function service_log($message, $verbose = true): void
    {
        if ($verbose) {
            echo (string)$message;
        }
    }
}
if (!function_exists('system_cron_configure')) {
    fwrite(STDERR, "WARN system_cron_configure missing\n");
    exit(1);
}
try {
    system_cron_configure(false);
    echo "OK crontab refreshed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
PHPEOF

install -d /usr/local/etc/rc.syshook.d/start
install_tree "$PLUGIN_DIR/etc/rc.syshook.d/start" \
             "/usr/local/etc/rc.syshook.d/start" 0755

install -d /etc/newsyslog.conf.d
install_tree "$PLUGIN_DIR/etc/newsyslog.conf.d" \
             "/etc/newsyslog.conf.d" 0644

# Ensure os-xray log files exist under /var/log (newsyslog: xray.conf).
# Migrate legacy /tmp paths from older installs.
echo "==> Preparing /var/log xray logs for newsyslog..."
for _pair in \
    "/tmp/xray_syshook.log:/var/log/xray-boot.log" \
    "/tmp/xray_autoroute.log:/var/log/xray-autoroute.log"
do
    _old="${_pair%%:*}"
    _new="${_pair##*:}"
    if [ -f "$_old" ] && [ ! -f "$_new" ]; then
        mv -f "$_old" "$_new"
        echo "[OK]  migrated $_old → $_new"
    else
        rm -f "$_old" "${_old}.0"
    fi
done
for _log in \
    /var/log/xray-core.log \
    /var/log/xray-boot.log \
    /var/log/xray-autoroute.log \
    /var/log/xray-watchdog.log
do
    if [ ! -f "$_log" ]; then
        : > "$_log"
    fi
    chmod 0644 "$_log"
done
echo "[OK]  /etc/newsyslog.conf.d/xray.conf (core, boot, autoroute, watchdog)"
echo "[OK]  bird/dnstap newsyslog: /usr/local/etc/newsyslog.conf.d/{bird,dnstap-bgp}.conf (install steps above)"

# Working directories for xray-core and tun2socks
install -d -m 0750 /usr/local/etc/xray-core
install -d -m 0750 /usr/local/tun2socks

# Write version file
echo "$PLUGIN_VERSION" > "$VERSION_FILE"
chmod 0644 "$VERSION_FILE"

echo "[OK]  Plugin files installed."

echo ""
echo "==> Filling BIRD router id, WAN interfaces and BGP source address from WAN..."
if xray_keep_config; then
    echo "[SKIP] WAN → General/BIRD (reinstall — keep configuration)"
    _WAN_BIRD_OK="SKIP"
else
_WAN_BIRD_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
@require_once('config.inc');
$script = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
if (!is_readable($script)) { echo "SKIP"; exit(0); }
require_once $script;
// First install only: seed Interface WAN4/WAN6 in General from detected WAN
if (function_exists('xray_bird_seed_wan_if_config')) {
    $seed = xray_bird_seed_wan_if_config();
    echo 'SEED wan4=' . ($seed['wan4'] ?? '') . ' wan6=' . ($seed['wan6'] ?? '')
        . ' saved=' . (!empty($seed['saved']) ? '1' : '0') . "\n";
}
if (!function_exists('xray_bird_apply_wan_addresses')) { echo "SKIP"; exit(0); }
xray_bird_apply_wan_addresses();
$v4 = function_exists('xray_wan_ipv4') ? xray_wan_ipv4() : '';
$v6 = function_exists('xray_wan_ipv6') ? xray_wan_ipv6() : '';
echo "OK v4=" . $v4 . " v6=" . $v6;
PHPEOF
) || true
    echo "$_WAN_BIRD_OK" | while IFS= read -r _line; do
        case "$_line" in
            SEED\ *) echo "[OK]  $_line" ;;
            OK\ v4=*) echo "[OK]  $_line" ;;
            *) [ -n "$_line" ] && echo "[INFO] $_line" ;;
        esac
    done
    if ! echo "$_WAN_BIRD_OK" | grep -q '^OK v4=[0-9]'; then
        echo "[INFO] PHP WAN lookup incomplete; using ifconfig/route for router id"
        xray_shell_fill_wan_bird
    fi
fi

# ── Шаг 4: Импорт существующего конфига ──────────────────────────────────────
# Импорт нужен только при ПЕРВОЙ установке — когда в config.xml ещё нет секции xray,
# но есть файловые конфиги от ручной установки xray-core/tun2socks.
# При обновлении (повторный install.sh) config.xml уже содержит настройки из GUI —
# перезаписывать их из файлового конфига нельзя (затрёт изменения пользователя).
echo ""
echo "==> Step 4: Importing existing config (if found)..."

CONFIG_XML_HAS_XRAY=0
NEEDS_MIGRATION=0
_PHP_OUT=$(php -r '
set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path());
require_once("config.inc");
$cfg = OPNsense\Core\Config::getInstance()->object();

// Any ArrayField instance (even custom/outbound-only) means GUI config exists — do not re-import.
$instances = $cfg->OPNsense->xray->instances ?? null;
if ($instances) {
    foreach ($instances->instance as $inst) {
        $uuid = (string)($inst["uuid"] ?? "");
        if ($uuid !== "") {
            echo "new";
            exit(0);
        }
    }
}

// v1.x: check old single-instance structure (needs migration)
$inst = $cfg->OPNsense->xray->instance ?? null;
if ($inst && ((string)($inst->server_address ?? "") !== "" || (string)($inst->vless_uuid ?? "") !== "" || (string)($inst->uuid ?? "") !== "")) {
    echo "old";
    exit(0);
}
' 2>/dev/null) || true
if [ "$_PHP_OUT" = "new" ]; then
    CONFIG_XML_HAS_XRAY=1
elif [ "$_PHP_OUT" = "old" ]; then
    CONFIG_XML_HAS_XRAY=1
    NEEDS_MIGRATION=1
fi

if [ "$CONFIG_XML_HAS_XRAY" = "1" ]; then
    echo "[SKIP] config.xml already has Xray settings (from GUI). Skipping file import to preserve your configuration."
elif [ "$HAS_EXISTING_CONFIG" = "1" ]; then
    import_existing_config
else
    echo "[SKIP] No existing config to import."
fi

# Drop empty placeholder "default"/unnamed instances left by older installers.
echo "==> Removing empty placeholder instances (default / empty name)..."
_PURGE_DEFAULT=$(xray_purge_placeholder_instances 2>/dev/null) || true
if echo "$_PURGE_DEFAULT" | grep -q '^OK:'; then
    echo "[OK]  Removed empty placeholder instance(s): ${_PURGE_DEFAULT#OK:}"
elif echo "$_PURGE_DEFAULT" | grep -q '^ERR:'; then
    echo "[WARN] Placeholder purge failed: ${_PURGE_DEFAULT#ERR:}"
else
    echo "[SKIP] No empty default placeholder instance"
fi

# ── Шаг 4.5: Миграция v1.x → v3.0.0 (single instance → ArrayField) ─────────
if [ "$NEEDS_MIGRATION" = "1" ]; then
    echo ""
    echo "==> Step 4.5: Migrating config.xml from v1.x to v3.0.0 (ArrayField)..."

    _MIGRATE_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();
$x   = $obj->OPNsense->xray ?? null;
if (!$x) { echo "SKIP"; exit(0); }

$old = $x->instance ?? null;
if (!$old) { echo "SKIP"; exit(0); }

// Уже есть новая структура — не мигрируем
if (isset($x->instances)) { echo "SKIP"; exit(0); }

// Копируем все поля из старого <instance> в новый <instances><instance uuid="...">
$instances = $x->addChild('instances');
$newInst   = $instances->addChild('instance');

// Генерируем UUID
$instUuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);
$newInst->addAttribute('uuid', $instUuid);

// Копируем дочерние элементы
$fields = [
    'name', 'config_mode', 'custom_config',
    'server_address', 'server_port', 'vless_uuid', 'flow',
    'reality_sni', 'reality_pubkey', 'reality_shortid', 'reality_fingerprint',
    'socks5_listen', 'socks5_port', 'tun_interface', 'mtu',
    'bypass_networks', 'loglevel',
];
foreach ($fields as $f) {
    $val = (string)($old->$f ?? '');
    if ($val !== '') {
        $newInst->addChild($f, htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    }
}
// v2.0.0: rename old <uuid> to <vless_uuid> (avoid ArrayField UUID conflict)
$oldUuid = (string)($old->uuid ?? '');
if ($oldUuid !== '') {
    $newInst->addChild('vless_uuid', htmlspecialchars($oldUuid, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
}

// Удаляем старый <instance> (singular)
$dom = dom_import_simplexml($old);
$dom->parentNode->removeChild($dom);

$cfg->save();
echo "OK";
PHPEOF
    ) || true

    if [ "$_MIGRATE_OK" = "OK" ]; then
        echo "[OK]  Migrated v1.x config to v3.0.0 ArrayField format."
    elif [ "$_MIGRATE_OK" = "SKIP" ]; then
        echo "[SKIP] Migration not needed."
    else
        warn "Migration failed. You may need to re-enter settings in the GUI."
    fi
fi

# ── Шаг 4.6: Rename <uuid> → <vless_uuid> in existing v2.0.0 instances ────────
# Avoids ArrayField UUID attribute conflict with VLESS user UUID field.
echo ""
echo "==> Step 4.6: Checking for <uuid> → <vless_uuid> rename..."

_RENAME_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();
$instances = $obj->OPNsense->xray->instances ?? null;
if (!$instances) { echo "SKIP"; exit(0); }

$changed = false;
foreach ($instances->instance as $inst) {
    $oldVal = (string)($inst->uuid ?? '');
    $newVal = (string)($inst->vless_uuid ?? '');
    if ($oldVal !== '' && $newVal === '') {
        $inst->addChild('vless_uuid', htmlspecialchars($oldVal, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $dom = dom_import_simplexml($inst->uuid);
        $dom->parentNode->removeChild($dom);
        $changed = true;
    }
}

if ($changed) {
    $cfg->save();
    echo "OK";
} else {
    echo "SKIP";
}
PHPEOF
) || true

if [ "$_RENAME_OK" = "OK" ]; then
    echo "[OK]  Renamed <uuid> to <vless_uuid> in config.xml."
elif [ "$_RENAME_OK" = "SKIP" ]; then
    echo "[SKIP] No rename needed."
else
    warn "Rename failed: $_RENAME_OK"
fi

# ── Шаг 4.7: Cleanup v1.x/v2.x PID files and stale flags ─────────────────────
echo ""
echo "==> Step 4.7: Cleaning up old runtime files..."
# v3.0.0: старые PID-файлы без UUID — tun2socks и xray-core не будут найдены per-instance stop
for _OLD_PID in /var/run/xray_core.pid /var/run/tun2socks.pid; do
    if [ -f "$_OLD_PID" ]; then
        _PID=$(cat "$_OLD_PID" 2>/dev/null)
        if [ -n "$_PID" ]; then
            kill "$_PID" 2>/dev/null || true
            echo "[OK]  Killed old process (PID=$_PID) from $_OLD_PID"
        fi
        rm -f "$_OLD_PID"
    fi
done
# Удаляем stale flags от некорректных UUID (e.g. xray_stopped_1.flag от configd %1)
rm -f /var/run/xray_stopped_1.flag 2>/dev/null
echo "[OK]  Old runtime files cleaned."

# ── Шаг 4.8: Миграция v3.0.x → v3.1.0 (use_ipv4/use_ipv6/dns_servers) ────────
echo ""
echo "==> Step 4.8: Adding v3.1.0 instance fields (IP stack, DNS)..."

_MIGRATE_310_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();
$instances = $obj->OPNsense->xray->instances ?? null;
if (!$instances) { echo "SKIP"; exit(0); }

$changed = false;
foreach ($instances->instance as $inst) {
    if (!isset($inst->use_ipv4)) {
        $inst->addChild('use_ipv4', '1');
        $changed = true;
    }
    if (!isset($inst->use_ipv6)) {
        $inst->addChild('use_ipv6', '0');
        $changed = true;
    }
    if (!isset($inst->dns_servers) || trim((string)$inst->dns_servers) === '') {
        if (isset($inst->dns_servers)) {
            $dom = dom_import_simplexml($inst->dns_servers);
            $dom->parentNode->removeChild($dom);
        }
        $inst->addChild('dns_servers', '1.1.1.1,8.8.8.8');
        $changed = true;
    }
}

if ($changed) {
    $cfg->save();
    echo "OK";
} else {
    echo "SKIP";
}
PHPEOF
) || true

if [ "$_MIGRATE_310_OK" = "OK" ]; then
    echo "[OK]  Added use_ipv4/use_ipv6/dns_servers to existing instances."
elif [ "$_MIGRATE_310_OK" = "SKIP" ]; then
    echo "[SKIP] v3.1.0 field migration not needed."
else
    warn "v3.1.0 field migration failed."
fi

# ── Шаг 4.9: Seed default BGP peers (disabled) ────────────────────────────────
echo ""
echo "==> Step 4.8b: Default General toggles (BGP/DNStap/Auto route off, System policy)..."
if xray_keep_config; then
    echo "[SKIP] General defaults (reinstall — keep configuration)"
else
_SEED_GENERAL_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();
if (!isset($obj->OPNsense)) { $obj->addChild('OPNsense'); }
if (!isset($obj->OPNsense->xray)) { $obj->OPNsense->addChild('xray'); }
$x = $obj->OPNsense->xray;
if (!isset($x->general)) { $x->addChild('general'); }
$g = $x->general;
$changed = false;
$defaults = [
    'bgp_enabled' => '0',
    'dnstap_bgp_enabled' => '0',
    'bgp_auto_route' => '0',
];
foreach ($defaults as $k => $v) {
    if (!isset($g->{$k})) {
        $g->addChild($k, $v);
        $changed = true;
    } elseif (trim((string)$g->{$k}) === '') {
        $g->{$k} = $v;
        $changed = true;
    }
}
// Missing/empty strategy only — do not overwrite an explicit user choice.
$strat = isset($g->routing_strategy) ? trim((string)$g->routing_strategy) : '';
if ($strat === '') {
    if (!isset($g->routing_strategy)) {
        $g->addChild('routing_strategy', 'system_policy');
    } else {
        $g->routing_strategy = 'system_policy';
    }
    $changed = true;
}
if ($changed) {
    $cfg->save();
    echo "OK";
} else {
    echo "SKIP";
}
PHPEOF
) || true
    if [ "$_SEED_GENERAL_OK" = "OK" ]; then
        echo "[OK]  General defaults applied (BGP/DNStap/Auto route off; strategy if empty → System policy)."
    elif [ "$_SEED_GENERAL_OK" = "SKIP" ]; then
        echo "[SKIP] General defaults already set."
    else
        warn "General defaults seed failed."
    fi
fi

echo "==> Step 4.9: Seeding default BGP peers if none exist..."
if xray_keep_config; then
    echo "[SKIP] BGP peer seed (reinstall — keep configuration)"
else
_SEED_BGP_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

function xray_bgp_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();
$xray = $obj->OPNsense->xray ?? null;
if (!$xray) { echo "SKIP"; exit(0); }

if (!isset($xray->bgppeers)) {
    $xray->addChild('bgppeers');
}
$bgp = $xray->bgppeers;
if (isset($bgp->peer)) {
    foreach ($bgp->peer as $p) {
        echo "SKIP";
        exit(0);
    }
}

$networkComm = '65444:120, 65444:200, 65444:210, 65444:700, 65444:710, 65444:720, 65444:730, 65444:740, 65444:750, 65444:760, 65444:770, 65444:780, 65444:790, 65444:800';

$birdPeers = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
if (is_readable($birdPeers)) {
    require_once $birdPeers;
}

$src4 = function_exists('xray_wan_ipv4') ? xray_wan_ipv4() : '';

$peers = [
    [
        'enabled' => '0', 'name' => 'refilter', 'local_as' => '65103',
        'neighbor' => '165.22.127.207', 'neighbor_as' => '65412',
        'source_address' => $src4,
        'ipv4' => '1', 'ipv4_import' => 'filter_refilter',
        'ipv4_community_name' => '', 'ipv4_community' => '',
        'ipv6' => '0', 'ipv6_import' => '',
        'ipv6_community_name' => '', 'ipv6_community' => '',
        'hold_time' => '240',
    ],
    [
        'enabled' => '0', 'name' => 'antifilter_download', 'local_as' => '65103',
        'neighbor' => '45.154.73.71', 'neighbor_as' => '65432',
        'source_address' => $src4,
        'ipv4' => '1', 'ipv4_import' => 'filter_antifilter_download',
        'ipv4_community_name' => 'community_ANTIFILTER_DOWNLOAD', 'ipv4_community' => '65432:500',
        'ipv6' => '0', 'ipv6_import' => '',
        'ipv6_community_name' => '', 'ipv6_community' => '',
        'hold_time' => '240',
    ],
    [
        'enabled' => '0', 'name' => 'antifilter_network', 'local_as' => '65103',
        'neighbor' => '45.148.244.55', 'neighbor_as' => '65444',
        'source_address' => $src4,
        'ipv4' => '1', 'ipv4_import' => 'filter_antifilter_network_v4',
        'ipv4_community_name' => 'community_ANTIFILTER_NETWORK', 'ipv4_community' => $networkComm,
        'ipv6' => '1', 'ipv6_import' => 'filter_antifilter_network_v6',
        'ipv6_community_name' => 'community_ANTIFILTER_NETWORK', 'ipv6_community' => $networkComm,
        'hold_time' => '240',
    ],
];

foreach ($peers as $row) {
    $peer = $bgp->addChild('peer');
    $peer->addAttribute('uuid', xray_bgp_uuid());
    foreach ($row as $k => $v) {
        $peer->addChild($k, htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    }
}
$cfg->save();
if (function_exists('xray_bird_apply_wan_addresses')) {
    xray_bird_apply_wan_addresses();
}
echo "OK";
PHPEOF
) || true

    if [ "$_SEED_BGP_OK" = "OK" ]; then
        echo "[OK]  Added default BGP peers (disabled)."
    elif [ "$_SEED_BGP_OK" = "SKIP" ]; then
        echo "[SKIP] BGP peers already present."
    else
        warn "BGP peer seed failed."
    fi
fi

# ── Шаг 4.10: Seed default BGP filters and communities ────────────────────────
echo ""
echo "==> Step 4.10: Seeding default BGP filters and communities if none exist..."
if xray_keep_config; then
    echo "[SKIP] BGP filter/community seed (reinstall — keep configuration)"
else
_SEED_BGP_EXTRA_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

function xray_bgp_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function xray_seed_array_if_empty($parent, string $childName, array $rows): bool
{
    if (isset($parent->{$childName})) {
        foreach ($parent->{$childName} as $item) {
            return false;
        }
    }
    foreach ($rows as $row) {
        $node = $parent->addChild($childName);
        $node->addAttribute('uuid', xray_bgp_uuid());
        foreach ($row as $k => $v) {
            $node->addChild($k, htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }
    }
    return true;
}

$cfg = OPNsense\Core\Config::getInstance();
$obj = $cfg->object();
$xray = $obj->OPNsense->xray ?? null;
if (!$xray) { echo "SKIP"; exit(0); }

if (!isset($xray->bgpfilters)) {
    $xray->addChild('bgpfilters');
}
if (!isset($xray->bgpcommunities)) {
    $xray->addChild('bgpcommunities');
}

$networkComm = '65444:120, 65444:200, 65444:210, 65444:700, 65444:710, 65444:720, 65444:730, 65444:740, 65444:750, 65444:760, 65444:770, 65444:780, 65444:790, 65444:800';
$changed = false;
$changed = xray_seed_array_if_empty($xray->bgpfilters, 'filter', [
    ['enabled' => '1', 'name' => 'filter_refilter', 'community' => '', 'family' => 'ipv4', 'tun_if' => 'ACTIVE_TUN4_IF'],
    ['enabled' => '1', 'name' => 'filter_antifilter_download', 'community' => 'community_ANTIFILTER_DOWNLOAD', 'family' => 'ipv4', 'tun_if' => 'ACTIVE_TUN4_IF'],
    ['enabled' => '1', 'name' => 'filter_antifilter_network_v4', 'community' => 'community_ANTIFILTER_NETWORK', 'family' => 'ipv4', 'tun_if' => 'ACTIVE_TUN4_IF'],
    ['enabled' => '1', 'name' => 'filter_antifilter_network_v6', 'community' => 'community_ANTIFILTER_NETWORK', 'family' => 'ipv6', 'tun_if' => 'ACTIVE_TUN6_IF'],
    ['enabled' => '1', 'name' => 'filter_dnstap_v4', 'community' => 'community_DNSTAP_PROXY', 'family' => 'ipv4', 'tun_if' => 'ACTIVE_TUN4_IF'],
    ['enabled' => '1', 'name' => 'filter_dnstap_v6', 'community' => 'community_DNSTAP_PROXY', 'family' => 'ipv6', 'tun_if' => 'ACTIVE_TUN6_IF'],
]) || $changed;
$changed = xray_seed_array_if_empty($xray->bgpcommunities, 'community', [
    ['enabled' => '1', 'name' => 'community_ANTIFILTER_DOWNLOAD', 'communities' => '65432:500'],
    ['enabled' => '1', 'name' => 'community_ANTIFILTER_NETWORK', 'communities' => $networkComm],
    ['enabled' => '1', 'name' => 'community_DNSTAP_PROXY', 'communities' => '65103:777'],
    ['enabled' => '1', 'name' => 'community_DNSTAP_DIRECT', 'communities' => '65103:555'],
    ['enabled' => '1', 'name' => 'community_DNSTAP_BLOCK', 'communities' => '65103:888'],
]) || $changed;

$script = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
if (is_readable($script)) {
    require_once $script;
    if (function_exists('xray_dnstap_ensure_bird_policy')) {
        xray_dnstap_ensure_bird_policy($xray, 65103);
        $changed = true;
    }
}

$byName = [];
if (isset($xray->bgpfilters->filter)) {
    foreach ($xray->bgpfilters->filter as $f) {
        $byName[(string)$f->name] = (string)$f['uuid'];
    }
}
if (isset($xray->bgppeers->peer)) {
    foreach ($xray->bgppeers->peer as $p) {
        foreach (['ipv4_import', 'ipv6_import'] as $field) {
            $v = trim((string)($p->{$field} ?? ''));
            if ($v === '' || !isset($byName[$v])) {
                continue;
            }
            $p->{$field} = $byName[$v];
            $changed = true;
        }
    }
}

if ($changed) {
    $cfg->save();
    echo "OK";
} else {
    echo "SKIP";
}
PHPEOF
) || true

    if [ "$_SEED_BGP_EXTRA_OK" = "OK" ]; then
        echo "[OK]  Added default BGP filters and communities."
    elif [ "$_SEED_BGP_EXTRA_OK" = "SKIP" ]; then
        echo "[SKIP] BGP filters/communities already present."
    else
        warn "BGP filter/community seed failed."
    fi
fi

echo ""
echo "==> Writing BIRD includes for enabled peers/filters/communities only..."
if xray_keep_config; then
    echo "[SKIP] BIRD include rewrite (reinstall — keep configuration)"
    _BIRD_INC_OK="SKIP"
else
_BIRD_INC_OK=$(php << 'PHPEOF'
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
@require_once('config.inc');
$script = '/usr/local/opnsense/scripts/Xray/xray-bird-peers.php';
if (!is_readable($script)) { echo "SKIP"; exit(0); }
require_once $script;
if (!function_exists('xray_bird_write_peers')) { echo "SKIP"; exit(0); }
xray_bird_write_peers();
if (function_exists('xray_bird_reload_config')) {
    xray_bird_reload_config();
}
echo "OK";
PHPEOF
) || true
    if [ "$_BIRD_INC_OK" = "OK" ]; then
        echo "[OK]  Regenerated bgp.conf / filters.inc / communities.inc from GUI enabled flags."
    else
        echo "[INFO] BIRD include rewrite: ${_BIRD_INC_OK:-empty}"
    fi
fi

if ! grep -q '^router id [0-9]' /usr/local/etc/bird/router_id.inc 2>/dev/null; then
    echo "==> WAN addresses still empty, filling from ifconfig/route..."
    xray_shell_fill_wan_bird
fi

# ── Шаг 5: Перезапуск configd ─────────────────────────────────────────────────
echo ""
echo "==> Step 5: Restarting configd..."
service configd restart
xray_restart_bird_log_services

# ── Шаг 6: Очистка кешей ──────────────────────────────────────────────────────
echo ""
echo "==> Step 6: Clearing cache..."
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/PHP_errors.log

# ── Шаг 7: Повторный purge placeholder (после миграций/seed) ─────────────────
echo ""
echo "==> Step 7: Re-check empty placeholder instances..."
_PURGE_DEFAULT2=$(xray_purge_placeholder_instances 2>/dev/null) || true
if echo "$_PURGE_DEFAULT2" | grep -q '^OK:'; then
    echo "[OK]  Removed empty placeholder instance(s): ${_PURGE_DEFAULT2#OK:}"
else
    echo "[SKIP] No empty placeholder left"
fi

# ── Шаг 8: Проверка/починка gateway_item для instance (defaultgw/fargw) ───────
echo ""
echo "==> Step 8: Verify instance gateways in config.xml..."
_GW_SCRIPT="/usr/local/opnsense/scripts/Xray/xray-gateways.php"
if [ -f "$_GW_SCRIPT" ]; then
    if /usr/local/bin/php "$_GW_SCRIPT" repair 2>&1; then
        echo "[OK]  instance gateways checked"
    else
        echo "[WARN] gateway repair failed — check System → Gateways manually"
    fi
else
    echo "[SKIP] $_GW_SCRIPT not found"
fi

# ── Шаг 9: Автостарт enabled-инстансов после переустановки ───────────────────
# Раньше после reinstall сервисы не поднимались, пока не нажмут Start All / Apply.
echo ""
echo "==> Step 9: Auto-start enabled instances (if Enable Xray is on)..."
_XRAY_EN=$(php -r '
set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path());
require_once("config.inc");
$cfg = OPNsense\Core\Config::getInstance()->object();
echo (string)($cfg->OPNsense->xray->general->enabled ?? "0");
' 2>/dev/null) || true
if [ "$_XRAY_EN" = "1" ]; then
    echo "    Enable Xray is on — starting instances via configctl..."
    if /usr/local/sbin/configctl xray start 2>&1; then
        echo "[OK]  xray start completed (BGP/DNStap follow General flags)"
    else
        echo "[WARN] configctl xray start failed — use Start All in the GUI"
    fi
else
    echo "    Enable Xray is off — skipping instance auto-start"
fi

# ── Шаг 9b: DNStap если Enable DNStap BGP, а Step 9 не бегал (Xray off) ───────
echo ""
echo "==> Step 9b: Sync DNStap BGP with General → Enable DNStap BGP..."
_DTAP_EN=$(php -r '
set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path());
require_once("config.inc");
$cfg = OPNsense\Core\Config::getInstance()->object();
echo (string)($cfg->OPNsense->xray->general->dnstap_bgp_enabled ?? "0");
' 2>/dev/null) || true
if [ "$_DTAP_EN" = "1" ]; then
    if [ "$_XRAY_EN" = "1" ]; then
        echo "    Enable DNStap BGP is on — already applied via xray start"
    elif [ -f /usr/local/opnsense/scripts/Xray/xray-dnstap-conf.php ]; then
        if /usr/local/sbin/configctl xray dnstapstart 2>&1; then
            echo "[OK]  dnstap-bgp started (Unbound DNSTap include applied)"
        else
            echo "[WARN] dnstapstart failed — use DNStap tab Start"
        fi
    else
        echo "[WARN] dnstap scripts missing"
    fi
else
    echo "    Enable DNStap BGP is off — skip"
fi

# ─────────────────────────────────────────────────────────────────────────────
# SUMMARY
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "============================================================"
echo "  os-xray v${PLUGIN_VERSION} installed successfully!"
echo "============================================================"
echo ""
echo "  Repository: ${REPO_URL} (${REPO_BRANCH})"
echo "  Check version:  configctl xray version"
echo ""

if [ "$CONFIG_XML_HAS_XRAY" = "1" ]; then
    echo "  Existing Xray settings preserved in config.xml."
    echo ""
    echo "  Quick steps:"
    echo "  1. Refresh browser (Ctrl+F5) → VPN → Xray"
    echo "  2. Verify your settings are intact"
    echo "  3. Instances should already be running if Enable Xray was on"
    echo ""
elif [ "$HAS_EXISTING_CONFIG" = "1" ]; then
    echo "  Existing config was detected and imported automatically."
    echo "  Your settings are already loaded in the GUI."
    echo ""
    echo "  Quick steps:"
    echo "  1. Refresh browser (Ctrl+F5) → VPN → Xray"
    echo "  2. Check that Instance tab shows your settings"
    echo "  3. General tab → verify 'Enable Xray' is checked"
    echo "  4. Click Apply if instances are not running"
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
echo "       IPv6 Address: configure Static IPv6 if instance IPv6 is enabled"
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
echo "  To uninstall:     sh install.sh uninstall"
echo "  Full wipe:        sh install.sh fulluninstall"
echo "                    (configs/logs/packages except unbound; keeps config.xml)"
echo ""
