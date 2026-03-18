**English** | [Русский](README.ru.md)

# os-xray

[![Release](https://img.shields.io/github/v/release/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/releases)
[![License](https://img.shields.io/github/license/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/blob/main/LICENSE)
[![Downloads](https://img.shields.io/github/downloads/MrTheory/os-xray/total)](https://github.com/MrTheory/os-xray/releases)
[![OPNsense](https://img.shields.io/badge/OPNsense-25.x%20%2F%2026.x-blue)](https://opnsense.org)
[![FreeBSD](https://img.shields.io/badge/FreeBSD-14.x%20amd64-red)](https://freebsd.org)

**Xray-core VPN plugin for OPNsense** — v2.0.0

Xray-core + tun2socks — native VPN client for OPNsense with selective routing support. VLESS+Reality via wizard or custom config.json (any protocol/transport). Bypasses DPI blocking by disguising traffic as legitimate TLS.

---

## Features

- **Custom Config** — two modes: Wizard (VLESS+Reality via GUI fields) or Custom (any xray-core config.json for any protocol and transport)
- **Import VLESS link** with one click — auto-detects wizard or custom mode; generates full config.json for xhttp, ws, grpc, h2, kcp transports
- Full VLESS+Reality parameter support (UUID, flow, SNI, PublicKey, ShortID, Fingerprint)
- Tunnel management via GUI: **VPN → Xray**
- Auto-detects and imports existing xray-core and tun2socks configs during installation
- Compatible with OPNsense selective routing (Firewall Aliases + Rules + Gateway)
- xray-core and tun2socks service status updates every 5 seconds in GUI
- **Start / Stop / Restart buttons** — manage the service directly from GUI without page reload
- **Validate Config button** — dry-run config through `xray -test` without stopping the service
- **Test Connection button** — verifies that xray-core is actually proxying traffic
- **Log tab** — Boot Log and Xray Core Log directly in GUI
- **Diagnostics tab** — TUN interface stats: IP, MTU, bytes, packets, process uptime, Ping RTT to VPN server; auto-refresh every 30 seconds
- **Copy Debug Info button** — collects diagnostics + logs into a modal for easy copy to issue reports
- **Bypass Networks** — configurable CIDR list of networks for direct routing (VPN bypass)
- **Watchdog** — automatic restart on xray-core or tun2socks crash (configurable)
- **Auto-start after reboot** — interface comes up automatically, no need to press Apply manually
- ACL permissions — GUI and API access only for authorized users with `page-vpn-xray` role

---

## Stack

```
xray-core (VLESS+Reality)
    ↓ SOCKS5 (default 127.0.0.1:10808, configurable)
tun2socks
    ↓ TUN interface proxytun2socks0
OPNsense Gateway PROXYTUN_GW
    ↓
Firewall Rules (selective routing)
```

---

## System Requirements

| Component  | Version                 |
|------------|-------------------------|
| OPNsense   | 25.x / 26.x            |
| FreeBSD    | 14.x amd64             |
| xray-core  | 24.x+ (recommended)    |
| tun2socks  | Any recent version     |

---

## Installation

**Option 1 — via git clone (recommended)**

```sh
cd /tmp
git clone https://github.com/MrTheory/os-xray.git
cd os-xray
sh install.sh
```

**Option 2 — via archive**

```sh
fetch -o /tmp/os-xray-v5.tar https://raw.githubusercontent.com/MrTheory/os-xray/refs/heads/main/os-xray-v5.tar
cd /tmp && tar xf os-xray-v5.tar && cd os-xray-v5
sh install.sh
```

The installer automatically:

- Shows current and new plugin version and asks for confirmation
- Checks xray-core version — if below 24.x, offers automatic upgrade
- Checks for xray-core and tun2socks binaries — if missing, displays download links
- Checks if the SOCKS5 port (default 10808) is already in use
- Finds existing configs and imports them into OPNsense (GUI fields are pre-filled)
- Copies all plugin files, restarts configd, clears caches
- Installs boot script for auto-start after reboot

Check installed version:
```sh
configctl xray version
```

---

## GUI Setup

Refresh browser (`Ctrl+F5`) → **VPN → Xray**

1. **Instance** tab → **Import VLESS link** button → paste link → **Parse & Fill**
   - For standard VLESS+Reality (TCP) links → automatically fills wizard fields
   - For links with other transports (xhttp, ws, grpc, h2, kcp) → automatically generates Custom Config JSON
2. *(Optional)* **Bypass Networks** field — specify networks that should bypass VPN (default: private networks 10/8, 172.16/12, 192.168/16)
3. *(Optional)* **Config Mode** → Custom — for manually pasting arbitrary config.json (any xray-core protocol/transport)
4. **General** tab → check **Enable Xray** (and **Enable Watchdog** if desired)
5. Press **Apply**
6. **Test Connection** button — verify the tunnel is working (shows HTTP 200)
7. **Validate Config** button — validate config without restarting the service

---

## Interface and Gateway

| Step | GUI Path | Value |
|------|----------|-------|
| Assign interface | Interfaces → Assignments | + Add: proxytun2socks0 |
| Enable and configure | Interfaces → \<name\> | Enable ✓, IPv4: Static, IP: `10.255.0.1/30` |
| **Prevent removal** | Interfaces → \<name\> | **Prevent interface removal ✓** |
| Create gateway | System → Gateways → Add | Gateway IP: `10.255.0.2`, Far Gateway ✓, Monitoring off ✓ |

> **Important:** the **Prevent interface removal** checkbox is mandatory — without it, OPNsense may remove the interface from config on reboot if tun2socks hasn't created it yet.

---

## Selective Routing

- **Firewall → Aliases** — create a list of IPs/networks/domains for VPN routing
- **Firewall → Rules → LAN** — add rule: Source = LAN net, Destination = alias, Gateway = PROXYTUN_GW

MSS Clamping is not required for Xray (unlike WireGuard).

---

## Outbound NAT (required!)

Without this, traffic through the tunnel won't be NATed and won't get past the VPN server.

**Firewall → NAT → Outbound**

1. Switch mode to **Hybrid outbound NAT rule generation** (if not already)
2. Add rule **+**:

| Field | Value |
|-------|-------|
| Interface | PROXYTUN (proxytun2socks0) |
| TCP/IP Version | IPv4 |
| Protocol | any |
| Source address | LAN net |
| Source port | any |
| Destination address | any |
| Destination port | any |
| Translation / target | Interface address |

> **Why:** OPNsense only NATs traffic through WAN by default. Traffic going through the TUN interface doesn't match automatic NAT rules. Without a manual rule, packets leave with the original LAN address (e.g. 192.168.1.x) and the VPN server drops them.

---

## Auto-start After Reboot

After reboot, the `proxytun2socks0` interface comes up automatically — xray and tun2socks start, the interface gets an IP, firewall rules are reloaded. No need to press Apply manually.

Works through two mechanisms with double-start protection (flock):
- **`xray_configure_do()`** — boot hook (priority 10), starts processes early in boot
- **`/usr/local/etc/rc.syshook.d/start/50-xray`** — final script, brings up the interface and applies routing/firewall when OPNsense is fully loaded

Log saved to `/tmp/xray_syshook.log` (append mode, rotated at 50 KB).

---

## Watchdog

When **Enable Watchdog** is on, cron checks xray-core and tun2socks every minute. If either process crashes, both are restarted automatically. Events are logged to `/var/log/xray-watchdog.log` (rotation: 3 files, 100 KB each).

Watchdog does not restart the service if it was stopped manually via the **Stop** button or **Apply** with Enable unchecked.

---

## Stopping the Service

When stopping (`Stop` in GUI or `Apply` with Enable unchecked):
1. Stops tun2socks — it destroys the TUN interface on exit
2. Stops xray-core
3. Sets intentional stop flag — watchdog won't restart the service

---

## Uninstall

```sh
cd /tmp/os-xray-v5
sh install.sh uninstall
```

---

## Troubleshooting

### Step-by-Step Diagnostics

Run these commands in order. Each step narrows down the problem:

```sh
# Step 1 — Plugin and binaries
configctl xray version
ls -la /usr/local/bin/xray-core /usr/local/tun2socks/tun2socks
/usr/local/bin/xray-core version          # xray-core version (should be 24.x+)

# Step 2 — Service status
configctl xray status                      # JSON: xray_core + tun2socks status
ps aux | grep -E 'xray|tun2socks'         # actual processes

# Step 3 — Config validation
configctl xray validate                    # dry-run without restart
# or directly:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config.json

# Step 4 — Network
ifconfig proxytun2socks0                   # TUN interface: UP + inet address?
netstat -rn | grep proxytun               # routing table entry?
curl --socks5 127.0.0.1:10808 -s -o /dev/null -w "%{http_code}" https://1.1.1.1 --max-time 10
# 200 = tunnel works, 000 = no connection

# Step 5 — Firewall
pfctl -sr | grep proxytun                 # firewall rules for TUN?
pfctl -sn | grep proxytun                 # NAT rules for TUN?

# Step 6 — Logs (most recent errors)
tail -30 /var/log/xray-core.log
cat /tmp/xray_syshook.log
tail -20 /var/log/xray-watchdog.log

# Step 7 — Configs
cat /usr/local/etc/xray-core/config.json
cat /usr/local/tun2socks/config.yaml
```

---

### Error Reference — All Plugin Error Messages

#### Start/Stop Errors

| Error Message | Cause | Solution |
|---|---|---|
| `xray-core not found at /usr/local/bin/xray-core` | Binary missing | Install xray-core (see Manual Binary Installation below) |
| `tun2socks not found at /usr/local/tun2socks/tun2socks` | Binary missing | Install tun2socks (see below) |
| `Xray is disabled in config` | Enable not checked | GUI → General → Enable Xray ✓ → Apply |
| `Another start is already in progress (lock held)` | Parallel start, lock file held | Wait 30s. If persists: `rm -f /var/run/xray_start.lock` |
| `ERROR: Failed to start Xray services` | xray-core or tun2socks crashed on start | Check `/var/log/xray-core.log` for details |
| `No config found in config.xml` | GUI fields empty | Import VLESS link or fill fields manually → Apply |

#### Config Validation Errors

| Error Message | Cause | Solution |
|---|---|---|
| `ERROR: config validation failed` + xray output | Invalid xray-core config | See xray output below the error for specifics |
| `ERROR: custom_config is empty` | Custom mode selected but textarea empty | Paste config.json into Custom Config field |
| `ERROR: custom_config is not valid JSON` | Malformed JSON in custom config | Validate JSON (check commas, brackets, quotes) |
| `ERROR: Cannot create temp file for validation` | /tmp full or permission issue | `df /tmp` to check disk space |
| `ERROR: config file not found after write` | Write failed | Check `/usr/local/etc/xray-core/` permissions |

#### xray-core Validation Errors (from `xray -test`)

| xray-core Output | Cause | Solution |
|---|---|---|
| `invalid user id` | Malformed UUID | Check UUID format: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` |
| `unknown transport protocol: xhttp` | xray-core < 24.x doesn't know xhttp | Upgrade xray-core to 24.x+ or plugin will auto-normalize to splithttp |
| `REALITY only supports TCP, H2, gRPC and DomainSocket` | Unsupported transport+security combo | Use Custom Config mode for non-TCP Reality, or change transport/security |
| `failed to dial` | VPN server unreachable | Check server address and port, test with `ping` or `nc -zv host port` |
| `address already in use` | SOCKS5 port occupied | `sockstat -4l \| grep 10808` — change port in GUI or stop conflicting process |
| `unknown config format` | Config file has wrong extension | Should not happen in v2.0.0+ (fixed in v1.9.1) |

#### Network Warnings

| Warning | Cause | Impact |
|---|---|---|
| `WARNING: Cannot read lo0 interface` | lo0 interface issue (very rare) | SOCKS5 listen on 127.0.0.1 may not work |
| `WARNING: Failed to add lo0 alias` | lo0 alias already exists or permission issue | Usually harmless — alias may already be set |

---

### VPN → Xray Menu Not Showing

```sh
# Clear OPNsense menu cache
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Then Ctrl+F5 in browser

# If still missing, verify plugin files exist:
ls /usr/local/opnsense/mvc/app/models/OPNsense/Xray/Menu/Menu.xml
ls /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/IndexController.php
```

---

### Tunnel Won't Start

```sh
# Run start manually to see full output:
/usr/local/opnsense/scripts/Xray/xray-service-control.php start

# If no output, check if enabled:
configctl xray status

# If "disabled in config":
# GUI → General → Enable Xray ✓ → Apply

# Validate config separately:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config.json
```

---

### Service "stopped" After Reboot

Xray auto-starts on boot if `General → Enable Xray` is checked. If it doesn't:

```sh
# 1. Check boot log
cat /tmp/xray_syshook.log

# 2. Check boot scripts exist and are executable
ls -la /usr/local/etc/rc.syshook.d/start/50-xray
ls -la /usr/local/etc/inc/plugins.inc.d/xray.inc

# 3. Check if stopped flag is set (prevents auto-start)
ls -la /var/run/xray_stopped.flag
# If present, remove it:
rm -f /var/run/xray_stopped.flag

# 4. Start manually
configctl xray start
```

---

### Apply / Start / Stop Not Working ("No response from configd")

```sh
# 1. Check configd is running
service configd status

# 2. If not running, start it
service configd start

# 3. Check for stuck lock file
ls -la /var/run/xray_start.lock
# If older than 1 minute:
rm -f /var/run/xray_start.lock

# 4. Check for stuck PHP process
ps aux | grep xray-service-control
# Kill if older than 2 minutes:
kill <PID>

# 5. Restart configd and retry
service configd restart
# Wait 3 seconds, then try GUI again

# 6. Check configd log for errors
tail -20 /var/log/system/latest.log
```

---

### Sites Won't Load Through VPN

**Symptom:** `curl --socks5` returns 200, but browser/LAN clients can't reach sites through VPN.

**Cause:** Missing Outbound NAT rule on TUN interface.

```sh
# Verify NAT rules exist for proxytun:
pfctl -sn | grep proxytun

# If empty — add Outbound NAT rule (see Outbound NAT section above)
```

**Other causes:**
```sh
# DNS not resolving through tunnel?
# Try IP directly:
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10

# If IP works but domain doesn't — DNS issue
# Check DNS settings in GUI → System → General → DNS

# MTU too high? Try lowering:
# GUI → Instance → MTU → 1400 (default is 1500)
```

---

### TUN Interface Issues

```sh
# Interface doesn't exist
ifconfig proxytun2socks0
# "does not exist" → tun2socks not running or crashed

# Check tun2socks process
ps aux | grep tun2socks
# If not running:
configctl xray start

# Interface exists but no IP
ifconfig proxytun2socks0
# If no "inet" line → syshook didn't assign IP
# Run manually:
ifconfig proxytun2socks0 10.255.0.1/30 up

# Interface UP but no traffic (Ibytes/Obytes = 0)
netstat -ibn | grep proxytun2socks0
# Check routing: is traffic going through this interface?
netstat -rn | grep proxytun
# If no routing entry → gateway not configured or firewall rules missing

# Interface removed after reboot
# GUI → Interfaces → <TUN interface> → Prevent interface removal ✓
# This is mandatory! Without it, OPNsense removes the interface on boot
```

---

### Custom Config Mode Issues

```sh
# Config not applying in custom mode
# 1. Verify config_mode is set to "custom":
cat /usr/local/etc/xray-core/config.json | head -5

# 2. Validate JSON syntax:
echo '<paste your json>' | python3 -m json.tool
# or:
configctl xray validate

# 3. Common JSON mistakes:
# - Trailing comma after last item in array/object
# - Single quotes instead of double quotes
# - Missing quotes around keys
# - Comments (// or /* */) — not allowed in JSON

# 4. xhttp transport on xray-core < 24.x:
/usr/local/bin/xray-core version
# If version starts with 1.x — plugin auto-normalizes xhttp→splithttp
# If still failing, upgrade xray-core to 24.x+
```

---

### Import VLESS Link Issues

```sh
# Import button does nothing / returns error
# 1. Check browser console (F12 → Console) for JS errors

# 2. Link must start with "vless://"
# Other protocols (vmess://, ss://) are not supported for import
# Use Custom Config mode and paste config.json manually

# 3. Link must have valid format:
# vless://UUID@HOST:PORT?params#name
# Required: UUID, HOST, PORT
# Optional: type, security, sni, fp, pbk, sid, flow, path, host, etc.

# 4. Port must be 1-65535, HOST must not be empty, UUID must not be empty

# 5. After import, press Apply to start the service!
```

---

### VLESS+Reality Connection Issues

```sh
# "failed to dial" in xray-core.log
# 1. Server reachable?
nc -zv <server_host> <server_port>
# or:
ping <server_host>

# 2. Wrong port?
# Check: GUI → Instance → Server Port

# 3. Reality handshake failure in log:
tail -50 /var/log/xray-core.log | grep -i reality
# Common causes:
# - Wrong PublicKey (pbk) — must match server's private key
# - Wrong ShortID (sid) — must match server config
# - Wrong SNI — must match server's allowed SNI list
# - Wrong fingerprint — try "chrome" (most compatible)
# - Clock skew — Reality is time-sensitive
#   ntpdate pool.ntp.org    # sync time
#   date                     # check current time

# 4. Server blocking your IP?
# Try from another network or check server logs
```

---

### Watchdog Issues

```sh
# Watchdog not restarting after crash
# 1. Is watchdog enabled?
# GUI → General → Enable Watchdog ✓

# 2. Is intentional stop flag set?
ls -la /var/run/xray_stopped.flag
# If present — watchdog intentionally ignores. Remove to allow restart:
rm -f /var/run/xray_stopped.flag

# 3. Is cron running watchdog?
crontab -l | grep xray
# Should show xray-watchdog.php entry

# 4. Check watchdog log
tail -30 /var/log/xray-watchdog.log
# "restart FAILED" → check xray-core.log for root cause

# Watchdog keeps restarting (restart loop)
# Root cause: xray-core or tun2socks crash immediately after start
# Fix the underlying issue first:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config.json
# Fix config, then watchdog will succeed
```

---

### xray-core Version Issues

```sh
# Check installed version
/usr/local/bin/xray-core version

# Version 1.x issues:
# - "xhttp" transport → not supported, use "splithttp" (plugin auto-normalizes)
# - REALITY + splithttp → NOT supported at all in 1.x
# - Some security features missing

# Upgrade to latest:
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip -o xray.zip xray
# Stop running instance first:
configctl xray stop
install -m 0755 /tmp/xray /usr/local/bin/xray-core
configctl xray start
/usr/local/bin/xray-core version    # verify
```

---

### Performance Issues

```sh
# High latency through tunnel
# 1. Check RTT to VPN server
ping -c 5 <server_host>
# GUI → Diagnostics tab shows Ping RTT

# 2. Check if tunnel is overloaded
netstat -ibn | grep proxytun2socks0
# Compare Ibytes/Obytes over time

# 3. Try lowering MTU
# GUI → Instance → MTU → 1400 (or 1300 for double encapsulation)

# 4. Check server load — the VPN server itself may be slow

# CPU/memory usage
ps aux | grep -E 'xray|tun2socks' | awk '{print $3, $4, $11}'
# Column 1 = %CPU, Column 2 = %MEM, Column 3 = process
# xray-core typically uses <5% CPU, ~30MB RAM
# tun2socks typically uses <3% CPU, ~15MB RAM
```

---

### Permission and File Issues

```sh
# Config files not writable
ls -la /usr/local/etc/xray-core/
ls -la /usr/local/tun2socks/
# config.json should be -rw-r----- (0640)
# Directories should be drwxr-x--- (0750)

# Fix permissions:
chmod 0640 /usr/local/etc/xray-core/config.json
chmod 0750 /usr/local/etc/xray-core/

# Binary not executable
chmod 0755 /usr/local/bin/xray-core
chmod 0755 /usr/local/tun2socks/tun2socks

# PHP errors (GUI not loading, API returning 500)
tail -30 /var/lib/php/tmp/PHP_errors.log

# configd can't execute scripts
ls -la /usr/local/opnsense/scripts/Xray/
# All .php files should be executable (-rwxr-xr-x)
chmod 0755 /usr/local/opnsense/scripts/Xray/*.php
service configd restart
```

---

### Zombie Processes

```sh
# Multiple xray-core or tun2socks processes
ps aux | grep -E 'xray-core|tun2socks' | grep -v grep

# If more than one of each — kill all and restart cleanly:
pkill -f xray-core
pkill -f tun2socks
rm -f /var/run/xray_core.pid /var/run/tun2socks.pid /var/run/xray_start.lock
sleep 1
configctl xray start

# PID file exists but process is dead
cat /var/run/xray_core.pid
ps -p <PID>
# If "No such process" — stale PID file
rm -f /var/run/xray_core.pid /var/run/tun2socks.pid
configctl xray start
```

---

### DNS Issues Through VPN

```sh
# Symptom: IPs work, domains don't resolve

# 1. Test DNS through tunnel
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10    # IP — should work
curl --socks5 127.0.0.1:10808 -s https://google.com --max-time 10  # domain — fails?

# 2. DNS may be leaking outside the tunnel
# Check which DNS servers are configured:
cat /etc/resolv.conf

# 3. Force DNS through tunnel:
# OPNsense GUI → System → General → DNS Servers
# Add DNS server (e.g. 1.1.1.1) with Gateway = PROXYTUN_GW
# This routes DNS queries through the VPN tunnel

# 4. Alternatively, use xray-core's built-in DNS
# In Custom Config mode, add "dns" section to config.json:
# {"dns": {"servers": ["1.1.1.1", "8.8.8.8"]}}
```

---

### Selective Routing Not Working

```sh
# Traffic goes direct instead of through VPN

# 1. Check firewall rules exist
pfctl -sr | grep PROXYTUN
# Should see rules with gateway = PROXYTUN_GW

# 2. Check alias is populated
pfctl -t <alias_name> -T show
# Should list IPs/networks

# 3. Check gateway is up
netstat -rn | grep proxytun
# Should show gateway 10.255.0.2 through proxytun2socks0

# 4. Gateway marked as down?
# GUI → System → Gateways → Status
# If "down" — see Gateway Monitoring section

# 5. Rules order matters! VPN rule must be ABOVE the default allow rule
# GUI → Firewall → Rules → LAN
# Drag VPN rule above the "Default allow LAN" rule
```

---

### Gateway Monitoring Issues

```sh
# Gateway shows "down" even though tunnel works

# dpinger (OPNsense gateway monitor) uses ICMP
# xray-core doesn't respond to ICMP → dpinger always sees it as "down"

# Solution 1: Disable monitoring for this gateway
# GUI → System → Gateways → Edit PROXYTUN_GW
# Disable Gateway Monitoring ✓ (or set Monitor IP to empty)

# Solution 2: Set monitoring IP to a public IP reachable through tunnel
# GUI → System → Gateways → Edit PROXYTUN_GW
# Monitor IP: 1.1.1.1 (or any IP that responds to ping through VPN)
# Note: this may not work if xray-core drops ICMP
```

---

### Log Locations

| Log | Path | Contains | Rotation |
|---|---|---|---|
| Xray Core | `/var/log/xray-core.log` | xray-core and tun2socks stderr | 600 KB, 3 files |
| Boot | `/tmp/xray_syshook.log` | Auto-start, IP, firewall reload | 50 KB, in-script |
| Watchdog | `/var/log/xray-watchdog.log` | Crash detection, restarts | 100 KB, 3 files |
| PHP errors | `/var/lib/php/tmp/PHP_errors.log` | OPNsense PHP (GUI, API) | OPNsense managed |
| System | `/var/log/system/latest.log` | configd errors | OPNsense managed |

---

### All Plugin Commands

```sh
# Service management
configctl xray start                  # start xray-core + tun2socks
configctl xray stop                   # stop both services
configctl xray restart                # stop + start
configctl xray reconfigure            # called by Apply button (stop + start)

# Diagnostics
configctl xray status                 # JSON: service status
configctl xray version                # JSON: plugin version
configctl xray validate               # dry-run config validation
configctl xray testconnect            # curl test through SOCKS5 proxy
configctl xray ifstats                # JSON: TUN interface stats, uptime, RTT

# Logs
configctl xray log                    # last 150 lines of boot log
configctl xray xraylog                # last 200 lines of xray-core log

# Manual script execution (for debugging)
/usr/local/opnsense/scripts/Xray/xray-service-control.php start
/usr/local/opnsense/scripts/Xray/xray-service-control.php status
/usr/local/opnsense/scripts/Xray/xray-service-control.php validate
/usr/local/opnsense/scripts/Xray/xray-ifstats.php
/usr/local/opnsense/scripts/Xray/xray-testconnect.php

# System
ps aux | grep -E 'xray|tun2socks'    # running processes
ifconfig proxytun2socks0              # TUN interface
netstat -rn | grep proxytun           # routing table
netstat -ibn | grep proxytun          # interface traffic stats
sockstat -4l | grep 10808             # who's using SOCKS5 port
tail -f /var/log/xray-core.log        # live log monitoring
```

---

### Reset and Reinstall

```sh
# Full reinstall without losing OPNsense config
sh install.sh uninstall
sh install.sh

# Nuclear option — clean everything and start fresh
configctl xray stop
rm -f /var/run/xray_*.pid /var/run/xray_*.lock /var/run/xray_*.flag
rm -f /usr/local/etc/xray-core/config.json
rm -f /usr/local/tun2socks/config.yaml
sh install.sh uninstall
sh install.sh
# Then re-import VLESS link and Apply
```

---

## File Structure

```
os-xray/
├── install.sh
├── CHANGELOG.md
└── plugin/
    ├── +MANIFEST                               ← FreeBSD pkg metadata
    ├── etc/
    │   ├── inc/plugins.inc.d/
    │   │   └── xray.inc                        ← service registration, boot hook, cron watchdog
    │   ├── newsyslog.conf.d/
    │   │   └── xray.conf                       ← xray-core.log and xray-watchdog.log rotation
    │   └── rc.syshook.d/start/
    │       └── 50-xray                         ← auto-start after reboot
    ├── scripts/Xray/
    │   ├── xray-service-control.php            ← xray-core and tun2socks management
    │   ├── xray-watchdog.php                   ← watchdog: process check and restart
    │   ├── xray-ifstats.php                    ← TUN interface stats for Diagnostics
    │   └── xray-testconnect.php                ← SOCKS5 connection test
    ├── service/conf/actions.d/
    │   └── actions_xray.conf                   ← configd actions
    └── mvc/app/
        ├── models/OPNsense/Xray/
        │   ├── General.xml / General.php       ← model: enable, watchdog (v1.0.1)
        │   ├── Instance.xml / Instance.php     ← model: connection parameters (v1.0.5)
        │   ├── ACL/ACL.xml                     ← access control (page-vpn-xray)
        │   └── Menu/Menu.xml                   ← VPN → Xray menu item
        ├── controllers/OPNsense/Xray/
        │   ├── IndexController.php
        │   ├── forms/general.xml
        │   ├── forms/instance.xml
        │   └── Api/
        │       ├── GeneralController.php
        │       ├── InstanceController.php
        │       ├── ServiceController.php       ← start/stop/restart/status/version/log/validate/diagnostics/testconnect
        │       └── ImportController.php        ← VLESS link parser
        └── views/OPNsense/Xray/
            └── general.volt
```

---

## Manual Binary Installation

The installer will notify you if binaries are missing. Below are commands for manual installation.

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

> The tun2socks filename after extraction may vary by version — check with `ls /tmp/tun2socks*`.

---

## Changelog

Full changelog in [CHANGELOG.md](CHANGELOG.md).

| Version | Changes |
|---------|---------|
| 2.0.0   | Custom Config (wizard/custom), VLESS import with auto-generated config.json for any transport, xhttp↔splithttp normalization, xray-core version check on install |
| 1.10.0  | Version check on install, `configctl xray version`, API version endpoint, Outbound NAT in README |
| 1.9.3   | P1 bugfixes (implode, socks5_port, validate tempfile, dedup), Bypass Networks, Copy Debug Info, Ping RTT, Diagnostics auto-refresh |
| 1.9.2   | Fix tun2socks fatal error on stop, watchdog log rotation |
| 1.9.1   | Hotfix validate: xray -test syntax, .json extension for tempnam |
| 1.9.0   | Improved SOCKS5 Listen Address hint |
| 1.8.0   | Security audit: proc_kill, watchdog stopped flag, validate tempfile |
| 1.7.0   | Fix GUI blocking, ifstats bytes fix, 50-xray from do_start |
| 1.6.0   | BUG-5/9, Watchdog E1, Diagnostics E4, Validate Config E5 |
| 1.5.0   | Log rotation, Start/Stop/Restart buttons, Xray Core Log tab |
| 1.4.0   | Security audit P0-P2, xray-testconnect, flock, stderr to log |
| 1.3.0   | Model versioning, `+MANIFEST`, `Changelog.md` |
| 1.2.0   | Log tab, Test Connection button, 5s status interval |
| 1.1.0   | Robust install.sh: PHP config parsing, check_port, log rotation |
| 1.0.1   | Fixed loglevel, TUN destroy on stop, flock |
| 1.0.0   | ACL, UUID validation, ImportController sanitization |
| 0.9.0   | Initial release |

---

## License

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.

---

## Author

Merkulov Pavel Sergeevich
February — March 2026

---

## Acknowledgements

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — Xray-core and VLESS+Reality protocol
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks) — tun2socks
- [OPNsense](https://opnsense.org) — open plugin architecture
- [yukh975](https://github.com/yukh975) — testing help
- [hohner36](https://github.com/hohner36) — testing and automation help
