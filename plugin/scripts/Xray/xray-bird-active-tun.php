#!/usr/local/bin/php
<?php
/**
 * Выбор активного tun2socks для BIRD (ACTIVE_TUN4_IF / ACTIVE_TUN6_IF).
 *
 * Среди включённых инстансов с живыми xray-core и tun2socks
 * (флаги IPv4/IPv6 инстанса на выбор TUN не влияют — только на JSON xray-core):
 *   1. для IPv4 и IPv6 отдельно берётся больший Weight (weight_ipv4 / weight_ipv6);
 *   2. через SOCKS5 проверяется curl -4 и -6;
 *   3. при равном весе побеждает меньшее «Время ответа»; если probe семьи пуст —
 *      берётся любой remaining enabled TUN, файл не оставляется на disabled instance.
 *
 * Пишет:
 *   /usr/local/etc/bird/active_tun_v4.inc
 *   /usr/local/etc/bird/active_tun_v6.inc
 *
 * При смене TUN вызывается birdc configure (без service bird onerestart).
 */

require_once('config.inc');
require_once(__DIR__ . '/xray-boot-log.inc.php');
require_once(__DIR__ . '/xray-if-test.inc.php');
require_once(__DIR__ . '/xray-gateways.php');

if (!defined('XRAY_BIRD_INC_DIR')) {
    define('XRAY_BIRD_INC_DIR', '/usr/local/etc/bird');
}
if (!defined('XRAY_AUTOROUTE_STATE')) {
    define('XRAY_AUTOROUTE_STATE', '/var/run/xray_autoroute_state.json');
}
if (!defined('XRAY_ACTIVE_TUN_LATENCY')) {
    define('XRAY_ACTIVE_TUN_LATENCY', '/var/run/xray_active_tun_latency.json');
}
if (!defined('XRAY_BIRDACTIVETUN_STATUS')) {
    define('XRAY_BIRDACTIVETUN_STATUS', '/var/run/xray_birdactivetun_status.json');
}

/**
 * GUI poll status for async Test auto route (birdactivetun → background "run").
 *
 * @param array<string,mixed> $patch
 */
function xray_bird_activetun_status_save(array $patch): void
{
    $cur = [];
    if (is_readable(XRAY_BIRDACTIVETUN_STATUS)) {
        $j = json_decode((string)file_get_contents(XRAY_BIRDACTIVETUN_STATUS), true);
        if (is_array($j)) {
            $cur = $j;
        }
    }
    $cur = array_merge($cur, $patch);
    @file_put_contents(
        XRAY_BIRDACTIVETUN_STATUS,
        json_encode($cur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    @chmod(XRAY_BIRDACTIVETUN_STATUS, 0644);
}

function xray_bird_activetun_status_load(): array
{
    if (!is_readable(XRAY_BIRDACTIVETUN_STATUS)) {
        return [
            'running' => false,
            'phase'   => 'idle',
            'result'  => '',
            'message' => '',
        ];
    }
    $j = json_decode((string)file_get_contents(XRAY_BIRDACTIVETUN_STATUS), true);
    if (!is_array($j)) {
        return [
            'running' => false,
            'phase'   => 'idle',
            'result'  => '',
            'message' => '',
        ];
    }
    return $j;
}

/**
 * Persist last probe times + active TUN for Instances grid columns.
 *
 * @param array<string,array{tun?:string,name?:string,v4_ok?:bool,v4_time?:float|null,v6_ok?:bool,v6_time?:float|null}> $byUuid
 */
function xray_bird_latency_save(array $byUuid, string $active4, string $active6): void
{
    $instances = [];
    foreach ($byUuid as $uuid => $row) {
        $uuid = (string)$uuid;
        if ($uuid === '') {
            continue;
        }
        $v4ok = !empty($row['v4_ok']);
        $v6ok = !empty($row['v6_ok']);
        $instances[$uuid] = [
            'tun'     => (string)($row['tun'] ?? ''),
            'name'    => (string)($row['name'] ?? ''),
            'v4_ok'   => $v4ok,
            'v4_time' => $v4ok ? (float)($row['v4_time'] ?? 0) : 0.0,
            'v6_ok'   => $v6ok,
            'v6_time' => $v6ok ? (float)($row['v6_time'] ?? 0) : 0.0,
        ];
    }
    $payload = [
        'updated_at' => time(),
        'active_v4'  => $active4,
        'active_v6'  => $active6,
        'instances'  => $instances,
    ];
    @file_put_contents(
        XRAY_ACTIVE_TUN_LATENCY,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    @chmod(XRAY_ACTIVE_TUN_LATENCY, 0644);

    // Keep Instances → Test result in sync with the same SOCKS probes as ACTIVE TUN.
    // (IF-health cron is every 5 min and was empty after uninstall until then.)
    $ifInc = __DIR__ . '/xray-if-test.inc.php';
    if ($instances !== [] && is_readable($ifInc)) {
        require_once $ifInc;
        if (function_exists('xray_if_test_save_many')) {
            $ifRows = [];
            foreach ($instances as $uuid => $row) {
                $v4ok = !empty($row['v4_ok']);
                $v6ok = !empty($row['v6_ok']);
                $ok = $v4ok || $v6ok;
                $parts = [];
                if ($v4ok) {
                    $parts[] = 'v4 ' . sprintf('%.3fs', (float)($row['v4_time'] ?? 0));
                }
                if ($v6ok) {
                    $parts[] = 'v6 ' . sprintf('%.3fs', (float)($row['v6_time'] ?? 0));
                }
                $ifRows[$uuid] = [
                    'ok'        => $ok,
                    'http_code' => $ok ? 200 : 0,
                    'message'   => $ok
                        ? ('OK — ' . implode(', ', $parts))
                        : 'Could not connect — SOCKS probe failed',
                ];
            }
            xray_if_test_save_many($ifRows, 'autoroute');
        }
    }
}

function xray_bird_latency_load(): array
{
    if (!is_readable(XRAY_ACTIVE_TUN_LATENCY)) {
        return [];
    }
    $j = json_decode((string)file_get_contents(XRAY_ACTIVE_TUN_LATENCY), true);
    return is_array($j) ? $j : [];
}

function xray_bird_active_tun_pid_alive(string $pidfile): bool
{
    if (!is_readable($pidfile)) {
        return false;
    }
    $pid = (int)trim((string)file_get_contents($pidfile));
    if ($pid <= 0) {
        return false;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

function xray_bird_active_tun_valid_if(string $tun): bool
{
    return preg_match('/^[a-z][a-z0-9]{0,13}[0-9]$/', $tun) === 1;
}

function xray_bird_active_tun_weight($raw): int
{
    $n = (int)(string)$raw;
    if ($n < 1) {
        return 1;
    }
    if ($n > 1000) {
        return 1000;
    }
    return $n;
}

/**
 * curl через SOCKS5. $ipFlag: '-4' или '-6'. Успех — HTTP 200–399.
 * Retries on HTTP 000 (SOCKS not ready yet during boot/syshook).
 * @return array{ok:bool,http:int,time:float,raw:string}
 */
function xray_bird_active_tun_curl(string $listen, int $port, string $ipFlag, int $retries = 3): array
{
    if ($port < 1 || $port > 65535) {
        $port = 10808;
    }
    $ipFlag = ($ipFlag === '-6') ? '-6' : '-4';
    $proxy = xray_socks_connect_host($listen) . ':' . $port;
    if (function_exists('xray_socks_wait_ready')) {
        xray_socks_wait_ready($listen, $port, 10);
    }
    $last = ['ok' => false, 'http' => 0, 'time' => 0.0, 'raw' => ''];
    $tries = max(1, $retries);
    for ($i = 0; $i < $tries; $i++) {
        $out = [];
        $rc = 0;
        exec(
            '/usr/local/bin/curl ' . $ipFlag
            . ' --socks5 ' . escapeshellarg($proxy)
            . ' -s -L -o /dev/null'
            . ' -w ' . escapeshellarg("Код ответа: %{http_code}\nВремя ответа: %{time_total} сек\n")
            . ' ' . escapeshellarg('https://google.com')
            . ' --max-time 10',
            $out,
            $rc
        );
        $raw  = trim(implode("\n", $out));
        $http = 0;
        $time = 0.0;
        if (preg_match('/Код ответа:\s*(\d+)/u', $raw, $m)) {
            $http = (int)$m[1];
        }
        if (preg_match('/Время ответа:\s*([0-9.]+)/u', $raw, $m)) {
            $time = (float)$m[1];
        }
        $ok = ($rc === 0 && $http >= 200 && $http < 400);
        $last = ['ok' => $ok, 'http' => $http, 'time' => $time, 'raw' => $raw];
        if ($ok) {
            return $last;
        }
        // Real HTTP from target — no point retrying. Only retry connect failures (000).
        if ($http !== 0 || $i + 1 >= $tries) {
            return $last;
        }
        usleep(500000);
        if (function_exists('xray_socks_wait_ready')) {
            xray_socks_wait_ready($listen, $port, 3);
        }
    }
    return $last;
}

function xray_bird_active_tun_configure(): void
{
    $birdc = '/usr/local/sbin/birdc';
    if (function_exists('xray_bird_reload_config')) {
        xray_bird_reload_config();
    } elseif (is_executable($birdc)) {
        exec($birdc . ' configure 2>&1');
    }
    // birdc configure updates ACTIVE_TUN*_IF, but ifname is already stored
    // on imported BGP routes. reload in re-runs import filters so kernel
    // export follows the new TUN.
    if (is_executable($birdc)) {
        exec($birdc . ' reload in all 2>&1', $out, $rc);
        echo 'bird: reload in all rc=' . $rc
            . ($out !== [] ? (' ' . implode('; ', $out)) : '') . "\n";
    }
    echo "bird: active tun changed — birdc configure + reload in all\n";
}

function xray_bird_active_tun_write(string $file, string $define, string $ifname): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $body = "# generated by os-xray — active tun2socks (weight + curl)\n"
        . 'define ' . $define . ' = "' . $ifname . '";' . "\n";
    file_put_contents($file, $body);
    @chmod($file, 0644);
}

function xray_bird_active_tun_auto_route_on($general): bool
{
    return (string)($general->bgp_auto_route ?? '0') === '1';
}

function xray_bird_active_tun_weight_used($general, string $family): bool
{
    if ($family === 'ipv6') {
        return (string)($general->bgp_weight_used_ipv6 ?? '0') === '1';
    }
    return (string)($general->bgp_weight_used_ipv4 ?? '0') === '1';
}

function xray_bird_active_tun_current(string $file, string $define): string
{
    if (!is_readable($file)) {
        return '';
    }
    $text = (string)file_get_contents($file);
    if (preg_match('/define\s+' . preg_quote($define, '/') . '\s*=\s*"([^"]+)"/', $text, $m)) {
        return $m[1];
    }
    return '';
}

function xray_bird_active_tun_pick(array $rows, string $weightKey, bool $useWeight): ?array
{
    if ($rows === []) {
        return null;
    }
    usort($rows, function ($a, $b) use ($weightKey, $useWeight) {
        if ($useWeight) {
            $wa = $a[$weightKey];
            $wb = $b[$weightKey];
            if ($wa !== $wb) {
                return $wb <=> $wa;
            }
        }
        if ($a['time'] !== $b['time']) {
            return $a['time'] <=> $b['time'];
        }
        return strcmp((string)$a['uuid'], (string)$b['uuid']);
    });
    return $rows[0];
}

/**
 * Если SOCKS-probe семьи пуст — всё равно выбрать TUN среди enabled+running.
 */
function xray_bird_active_tun_pick_or_fallback(
    ?array $best,
    array $cands,
    string $weightKey,
    bool $useWeight,
    string $family
): ?array {
    if ($best !== null) {
        return $best;
    }
    $rows = [];
    foreach ($cands as $c) {
        $rows[] = [
            'uuid'      => $c['uuid'],
            'name'      => $c['name'],
            'tun'       => $c['tun'],
            'weight_v4' => $c['weight_v4'],
            'weight_v6' => $c['weight_v6'],
            'time'      => 9999.0,
        ];
    }
    $pick = xray_bird_active_tun_pick($rows, $weightKey, $useWeight);
    if ($pick !== null) {
        echo "active tun {$family}: no SOCKS for this family — fallback {$pick['tun']} ({$pick['name']})\n";
    }
    return $pick;
}

/** Кандидаты Auto route / one-shot: только instance.enabled=1 + живые PID. */
function xray_bird_active_tun_candidates($ins): array
{
    $out = [];
    if (!$ins) {
        return $out;
    }
    foreach ($ins->instance as $inst) {
        $uuid = (string)$inst['uuid'];
        if ($uuid === '' || (string)($inst->enabled ?? '1') !== '1') {
            continue;
        }
        if (file_exists('/var/run/xray_stopped_' . $uuid . '.flag')) {
            continue;
        }
        if (!xray_bird_active_tun_pid_alive('/var/run/xray_core_' . $uuid . '.pid')) {
            continue;
        }
        if (!xray_bird_active_tun_pid_alive('/var/run/tun2socks_' . $uuid . '.pid')) {
            continue;
        }
        $tun = trim((string)($inst->tun_interface ?? ''));
        if ($tun === '') {
            $tun = 'proxytun2socks0';
        }
        if (!xray_bird_active_tun_valid_if($tun)) {
            continue;
        }
        $listen = (string)($inst->socks5_listen ?? '127.0.0.1') ?: '127.0.0.1';
        $port   = (int)(string)($inst->socks5_port ?? 10808);
        $out[] = [
            'uuid'      => $uuid,
            'name'      => (string)($inst->name ?? $uuid),
            'tun'       => $tun,
            'listen'    => $listen,
            'port'      => $port,
            'weight_v4' => xray_bird_active_tun_weight($inst->weight_ipv4 ?? 1),
            'weight_v6' => xray_bird_active_tun_weight($inst->weight_ipv6 ?? 1),
        ];
    }
    return $out;
}

/**
 * @return array{ok:bool, changed:bool}
 */
function xray_bird_active_tun_commit(?array $best4, ?array $best6, string $prev4, string $prev6): array
{
    $changed = false;
    if ($best4 !== null) {
        xray_bird_active_tun_write(
            XRAY_BIRD_INC_DIR . '/active_tun_v4.inc',
            'ACTIVE_TUN4_IF',
            $best4['tun']
        );
        $t = $best4['time'];
        echo "active tun v4: {$best4['tun']} ({$best4['name']}) weight={$best4['weight_v4']} time={$t}\n";
        if ($best4['tun'] !== $prev4) {
            $changed = true;
            echo "active tun v4 changed: {$prev4} -> {$best4['tun']}\n";
        }
    } else {
        echo "active tun v4: no working instance — file unchanged\n";
    }
    if ($best6 !== null) {
        xray_bird_active_tun_write(
            XRAY_BIRD_INC_DIR . '/active_tun_v6.inc',
            'ACTIVE_TUN6_IF',
            $best6['tun']
        );
        $t = $best6['time'];
        echo "active tun v6: {$best6['tun']} ({$best6['name']}) weight={$best6['weight_v6']} time={$t}\n";
        if ($best6['tun'] !== $prev6) {
            $changed = true;
            echo "active tun v6 changed: {$prev6} -> {$best6['tun']}\n";
        }
    } else {
        echo "active tun v6: no working instance — file unchanged\n";
    }
    $ok = ($best4 !== null || $best6 !== null);
    $sel4 = $best4['tun'] ?? ($prev4 !== '' ? $prev4 . ' (unchanged)' : 'none');
    $sel6 = $best6['tun'] ?? ($prev6 !== '' ? $prev6 . ' (unchanged)' : 'none');
    echo "active tun selected: IPv4={$sel4} IPv6={$sel6}\n";
    return ['ok' => $ok, 'changed' => $ok && $changed];
}

function xray_bird_avg_times(array $nums): ?float
{
    if ($nums === []) {
        return null;
    }
    return array_sum($nums) / count($nums);
}

function xray_bird_autoroute_empty(int $target = 3): array
{
    return [
        'polls_done'   => 0,
        'polls_target' => $target,
        'last_poll_at' => 0,
        'samples'      => [],
    ];
}

function xray_bird_autoroute_load(): array
{
    if (!is_readable(XRAY_AUTOROUTE_STATE)) {
        return xray_bird_autoroute_empty();
    }
    $j = json_decode((string)file_get_contents(XRAY_AUTOROUTE_STATE), true);
    if (!is_array($j)) {
        return xray_bird_autoroute_empty();
    }
    return array_merge(xray_bird_autoroute_empty(), $j);
}

function xray_bird_autoroute_save(array $state): void
{
    file_put_contents(
        XRAY_AUTOROUTE_STATE,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    @chmod(XRAY_AUTOROUTE_STATE, 0644);
}

function xray_bird_autoroute_fmt(?float $v): string
{
    return $v === null ? 'n/a' : sprintf('%.3f', $v);
}

/**
 * Выбор по средним из накопленных попыток; затем пустой новый цикл.
 */
function xray_bird_autoroute_finalize(array $state, $general, string $reason): void
{
    echo "auto route: finalize ({$reason})\n";
    $v4ok = [];
    $v6ok = [];
    $cfg  = OPNsense\Core\Config::getInstance()->object();
    $ins  = $cfg->OPNsense->xray->instances ?? null;
    $live = [];
    foreach (xray_bird_active_tun_candidates($ins) as $c) {
        $live[$c['uuid']] = true;
    }
    foreach ($state['samples'] as $uuid => $s) {
        $uuid = (string)$uuid;
        if (empty($live[$uuid])) {
            echo "  skip {$uuid} — instance not enabled or not running\n";
            continue;
        }
        $avg4 = xray_bird_avg_times($s['v4'] ?? []);
        $avg6 = xray_bird_avg_times($s['v6'] ?? []);
        $n4   = count($s['v4'] ?? []);
        $n6   = count($s['v6'] ?? []);
        echo "  [{$s['name']}] {$s['tun']} IPv4 samples=" . json_encode($s['v4'] ?? [])
            . " avg=" . xray_bird_autoroute_fmt($avg4) . " (n={$n4})"
            . " IPv6 samples=" . json_encode($s['v6'] ?? [])
            . " avg=" . xray_bird_autoroute_fmt($avg6) . " (n={$n6})\n";
        $base = [
            'uuid'      => $uuid,
            'name'      => (string)($s['name'] ?? $uuid),
            'tun'       => (string)($s['tun'] ?? ''),
            'weight_v4' => (int)($s['weight_v4'] ?? 1),
            'weight_v6' => (int)($s['weight_v6'] ?? 1),
        ];
        if ($avg4 !== null && xray_bird_active_tun_valid_if($base['tun'])) {
            $v4ok[] = $base + ['time' => $avg4];
        }
        if ($avg6 !== null && xray_bird_active_tun_valid_if($base['tun'])) {
            $v6ok[] = $base + ['time' => $avg6];
        }
    }
    $cands = xray_bird_active_tun_candidates($ins);
    $best4 = xray_bird_active_tun_pick_or_fallback(
        xray_bird_active_tun_pick(
            $v4ok,
            'weight_v4',
            xray_bird_active_tun_weight_used($general, 'ipv4')
        ),
        $cands,
        'weight_v4',
        xray_bird_active_tun_weight_used($general, 'ipv4'),
        'v4'
    );
    $best6 = xray_bird_active_tun_pick_or_fallback(
        xray_bird_active_tun_pick(
            $v6ok,
            'weight_v6',
            xray_bird_active_tun_weight_used($general, 'ipv6')
        ),
        $cands,
        'weight_v6',
        xray_bird_active_tun_weight_used($general, 'ipv6'),
        'v6'
    );
    $prev4 = xray_bird_active_tun_current(XRAY_BIRD_INC_DIR . '/active_tun_v4.inc', 'ACTIVE_TUN4_IF');
    $prev6 = xray_bird_active_tun_current(XRAY_BIRD_INC_DIR . '/active_tun_v6.inc', 'ACTIVE_TUN6_IF');
    $res = xray_bird_active_tun_commit($best4, $best6, $prev4, $prev6);
    $latency = [];
    foreach ($state['samples'] as $uuid => $s) {
        $uuid = (string)$uuid;
        $avg4 = xray_bird_avg_times($s['v4'] ?? []);
        $avg6 = xray_bird_avg_times($s['v6'] ?? []);
        $latency[$uuid] = [
            'tun'     => (string)($s['tun'] ?? ''),
            'name'    => (string)($s['name'] ?? $uuid),
            'v4_ok'   => $avg4 !== null,
            'v4_time' => $avg4 ?? 0.0,
            'v6_ok'   => $avg6 !== null,
            'v6_time' => $avg6 ?? 0.0,
        ];
    }
    $act4 = $best4['tun'] ?? $prev4;
    $act6 = $best6['tun'] ?? $prev6;
    xray_bird_latency_save($latency, $act4, $act6);
    if (!empty($res['changed'])) {
        xray_bird_active_tun_configure();
    }
    if (function_exists('xray_gateways_apply_autoroute_priorities')) {
        $rows = [];
        foreach ($latency as $uuid => $L) {
            $rows[] = is_array($L) ? $L : [];
        }
        xray_gateways_apply_autoroute_priorities($act4, $act6, $rows);
    }
}

function xray_bird_autoroute_tick(): void
{
    $cfg     = OPNsense\Core\Config::getInstance()->object();
    $general = $cfg->OPNsense->xray->general ?? null;
    $ins     = $cfg->OPNsense->xray->instances ?? null;
    if ((string)($general->enabled ?? '0') !== '1' || (string)($general->bgp_enabled ?? '0') !== '1') {
        echo "auto route: plugin or BGP disabled\n";
        return;
    }
    if (!xray_bird_active_tun_auto_route_on($general)) {
        echo "auto route: disabled\n";
        return;
    }

    if (function_exists('xray_gateways_ensure_missing_instances_pre_autoroute')) {
        xray_gateways_ensure_missing_instances_pre_autoroute();
    }

    $now      = time();
    $interval = (int)(string)($general->bgp_auto_route_interval ?? 10);
    if ($interval < 1) {
        $interval = 10;
    }
    $target = (int)(string)($general->bgp_auto_route_polls ?? 3);
    if ($target < 1) {
        $target = 1;
    }
    if ($target > 20) {
        $target = 20;
    }

    $state = xray_bird_autoroute_load();
    if ((int)($state['polls_target'] ?? 0) !== $target && (int)($state['polls_done'] ?? 0) > 0) {
        echo "auto route: polls target changed, drop in-progress cycle\n";
        $state = xray_bird_autoroute_empty($target);
    }
    $state['polls_target'] = $target;

    $wait = $interval * 60;
    $last = (int)($state['last_poll_at'] ?? 0);
    if ($last > 0 && ($now - $last) < $wait) {
        echo 'auto route: next poll in ' . ($wait - ($now - $last)) . "s\n";
        return;
    }

    $cands = xray_bird_active_tun_candidates($ins);
    $live  = [];
    foreach ($cands as $c) {
        $live[$c['uuid']] = true;
    }
    foreach (array_keys($state['samples'] ?? []) as $sid) {
        if (empty($live[$sid])) {
            unset($state['samples'][$sid]);
        }
    }
    $round = (int)$state['polls_done'] + 1;
    echo "auto route: poll {$round}/{$target} (interval {$interval} min, enabled instances only)\n";

    $ok4 = [];
    $ok6 = [];
    $byTun = [];
    foreach ($cands as $c) {
        $uuid = $c['uuid'];
        $byTun[$c['tun']] = $c;
        if (!isset($state['samples'][$uuid]) || !is_array($state['samples'][$uuid])) {
            $state['samples'][$uuid] = ['v4' => [], 'v6' => []];
        }
        $state['samples'][$uuid]['name']      = $c['name'];
        $state['samples'][$uuid]['tun']       = $c['tun'];
        $state['samples'][$uuid]['weight_v4'] = $c['weight_v4'];
        $state['samples'][$uuid]['weight_v6'] = $c['weight_v6'];
        $state['samples'][$uuid]['v4']        = $state['samples'][$uuid]['v4'] ?? [];
        $state['samples'][$uuid]['v6']        = $state['samples'][$uuid]['v6'] ?? [];

        echo "  [{$c['name']}] {$c['tun']} socks {$c['listen']}:{$c['port']}\n";
        $p = xray_bird_active_tun_curl($c['listen'], $c['port'], '-4');
        echo '    -4: ' . ($p['raw'] !== '' ? str_replace("\n", '; ', $p['raw']) : 'Код ответа: 0') . "\n";
        if ($p['ok']) {
            $state['samples'][$uuid]['v4'][] = $p['time'];
            $ok4[$uuid] = true;
        }
        $p = xray_bird_active_tun_curl($c['listen'], $c['port'], '-6');
        echo '    -6: ' . ($p['raw'] !== '' ? str_replace("\n", '; ', $p['raw']) : 'Код ответа: 0') . "\n";
        if ($p['ok']) {
            $state['samples'][$uuid]['v6'][] = $p['time'];
            $ok6[$uuid] = true;
        }
        $a4 = xray_bird_avg_times($state['samples'][$uuid]['v4']);
        $a6 = xray_bird_avg_times($state['samples'][$uuid]['v6']);
        echo '    running avg IPv4=' . xray_bird_autoroute_fmt($a4)
            . ' IPv6=' . xray_bird_autoroute_fmt($a6) . "\n";
    }

    $abort = false;
    $prev4 = xray_bird_active_tun_current(XRAY_BIRD_INC_DIR . '/active_tun_v4.inc', 'ACTIVE_TUN4_IF');
    $prev6 = xray_bird_active_tun_current(XRAY_BIRD_INC_DIR . '/active_tun_v6.inc', 'ACTIVE_TUN6_IF');
    if ($prev4 !== '' && !isset($byTun[$prev4])) {
        echo "auto route: active IPv4 tun {$prev4} is not enabled/running — abort cycle\n";
        $abort = true;
    }
    if ($prev6 !== '' && !isset($byTun[$prev6])) {
        echo "auto route: active IPv6 tun {$prev6} is not enabled/running — abort cycle\n";
        $abort = true;
    }

    $state['polls_done']   = $round;
    $state['last_poll_at'] = $now;
    xray_bird_autoroute_save($state);

    // Live grid snapshot (running averages + current ACTIVE_TUN* files)
    $latSnap = [];
    foreach ($state['samples'] as $sid => $s) {
        $a4 = xray_bird_avg_times($s['v4'] ?? []);
        $a6 = xray_bird_avg_times($s['v6'] ?? []);
        $latSnap[(string)$sid] = [
            'tun'     => (string)($s['tun'] ?? ''),
            'name'    => (string)($s['name'] ?? $sid),
            'v4_ok'   => $a4 !== null,
            'v4_time' => $a4 ?? 0.0,
            'v6_ok'   => $a6 !== null,
            'v6_time' => $a6 ?? 0.0,
        ];
    }
    xray_bird_latency_save($latSnap, $prev4, $prev6);

    if ($abort || $round >= $target) {
        $reason = $abort ? 'active tun lost after poll ' . $round : "completed {$target} polls";
        xray_bird_autoroute_finalize($state, $general, $reason);
        $fresh = xray_bird_autoroute_empty($target);
        $fresh['last_poll_at'] = $now;
        xray_bird_autoroute_save($fresh);
        echo "auto route: new cycle starts after {$interval} min\n";
        return;
    }
    echo 'auto route: stored poll ' . $round . "/{$target} in " . XRAY_AUTOROUTE_STATE . "\n";
}

/**
 * Старт BIRD / Enable BGP Apply: по одному curl на семейство.
 * Серия попыток Auto route сюда не входит — только cron.
 *
 * @return array{ok:bool, changed:bool}
 */
function xray_bird_select_and_write_active_tun(): array
{
    $cfg     = OPNsense\Core\Config::getInstance()->object();
    $general = $cfg->OPNsense->xray->general ?? null;
    $ins     = $cfg->OPNsense->xray->instances ?? null;
    $v4ok    = [];
    $v6ok    = [];
    $latency = [];

    if ((string)($general->enabled ?? '0') !== '1' || !$ins) {
        echo "active tun: plugin disabled or no instances — files unchanged\n";
        return ['ok' => false, 'changed' => false];
    }

    if (function_exists('xray_gateways_ensure_missing_instances_pre_autoroute')) {
        xray_gateways_ensure_missing_instances_pre_autoroute();
    }

    $useWeight4 = xray_bird_active_tun_weight_used($general, 'ipv4');
    $useWeight6 = xray_bird_active_tun_weight_used($general, 'ipv6');
    $prev4        = xray_bird_active_tun_current(XRAY_BIRD_INC_DIR . '/active_tun_v4.inc', 'ACTIVE_TUN4_IF');
    $prev6        = xray_bird_active_tun_current(XRAY_BIRD_INC_DIR . '/active_tun_v6.inc', 'ACTIVE_TUN6_IF');
    $cands        = xray_bird_active_tun_candidates($ins);

    foreach ($cands as $c) {
        $uuid   = $c['uuid'];
        $name   = $c['name'];
        $tun    = $c['tun'];
        $listen = $c['listen'];
        $port   = $c['port'];
        $w4     = $c['weight_v4'];
        $w6     = $c['weight_v6'];

        echo "active tun [{$name}] {$tun} socks {$listen}:{$port} weight4={$w4} weight6={$w6}\n";

        $base = [
            'uuid'      => $uuid,
            'name'      => $name,
            'tun'       => $tun,
            'weight_v4' => $w4,
            'weight_v6' => $w6,
        ];
        $probe4 = xray_bird_active_tun_curl($listen, $port, '-4');
        echo "  -4: " . ($probe4['raw'] !== '' ? str_replace("\n", '; ', $probe4['raw']) : 'Код ответа: 0') . "\n";
        if ($probe4['ok']) {
            echo "  best -4: Код ответа: {$probe4['http']}; Время ответа: {$probe4['time']} сек\n";
            $v4ok[] = $base + ['time' => $probe4['time']];
        }
        $probe6 = xray_bird_active_tun_curl($listen, $port, '-6');
        echo "  -6: " . ($probe6['raw'] !== '' ? str_replace("\n", '; ', $probe6['raw']) : 'Код ответа: 0') . "\n";
        if ($probe6['ok']) {
            echo "  best -6: Код ответа: {$probe6['http']}; Время ответа: {$probe6['time']} сек\n";
            $v6ok[] = $base + ['time' => $probe6['time']];
        }
        $latency[$uuid] = [
            'tun'     => $tun,
            'name'    => $name,
            'v4_ok'   => $probe4['ok'],
            'v4_time' => $probe4['ok'] ? $probe4['time'] : 0.0,
            'v6_ok'   => $probe6['ok'],
            'v6_time' => $probe6['ok'] ? $probe6['time'] : 0.0,
        ];
    }

    $best4 = xray_bird_active_tun_pick_or_fallback(
        xray_bird_active_tun_pick($v4ok, 'weight_v4', $useWeight4),
        $cands,
        'weight_v4',
        $useWeight4,
        'v4'
    );
    $best6 = xray_bird_active_tun_pick_or_fallback(
        xray_bird_active_tun_pick($v6ok, 'weight_v6', $useWeight6),
        $cands,
        'weight_v6',
        $useWeight6,
        'v6'
    );
    $res = xray_bird_active_tun_commit($best4, $best6, $prev4, $prev6);
    $act4 = $best4['tun'] ?? $prev4;
    $act6 = $best6['tun'] ?? $prev6;
    xray_bird_latency_save($latency, $act4, $act6);
    if (function_exists('xray_gateways_apply_autoroute_priorities')) {
        $rows = [];
        foreach ($latency as $uuid => $L) {
            $rows[] = is_array($L) ? $L : [];
        }
        xray_gateways_apply_autoroute_priorities($act4, $act6, $rows);
    }
    return $res;
}

if (count(debug_backtrace()) === 0) {
    ob_start();
    $mode = $argv[1] ?? '';

    if ($mode === 'status') {
        ob_end_clean();
        echo json_encode(xray_bird_activetun_status_load(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    // GUI birdactivetun (no args): SOCKS probes × N instances easily exceed nginx 60s → HTTP 504.
    // Schedule the real work and return OK immediately; cron still uses sync "autoroute".
    if ($mode === '') {
        $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : '/usr/local/bin/php';
        if (!is_executable($php)) {
            $php = '/usr/local/bin/php';
        }
        $token = bin2hex(random_bytes(8));
        $startedAt = time();
        xray_bird_activetun_status_save([
            'running'    => true,
            'phase'      => 'scheduled',
            'result'     => '',
            'message'    => 'Auto route running...',
            'token'      => $token,
            'started_at' => $startedAt,
            'finished_at'=> 0,
            'pid'        => 0,
        ]);
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' run ' . escapeshellarg($token);
        // Child writes Autoroute Log via xray_autoroute_log_block; avoid duplicate raw redirect.
        $out = [];
        exec($cmd . ' >/dev/null 2>&1 & echo $!', $out);
        $pid = (int)trim((string)($out[0] ?? '0'));
        if ($pid > 0) {
            xray_bird_activetun_status_save(['pid' => $pid]);
        }
        echo "RESULT:OK\n";
        $buf = (string)ob_get_flush();
        if ($buf !== '') {
            xray_autoroute_log_block('Auto route (manual scheduled)', $buf);
        }
        exit(0);
    }

    $runToken = '';
    if ($mode === 'run') {
        $runToken = (string)($argv[2] ?? '');
        xray_bird_activetun_status_save([
            'running' => true,
            'phase'   => 'running',
            'message' => 'Auto route running...',
            'pid'     => getmypid() ?: 0,
        ]);
    }

    $resultOk = true;
    try {
        if ($mode === 'autoroute') {
            xray_bird_autoroute_tick();
        } else {
            // mode "run" (background from GUI) or any other → one-shot select
            $sel = xray_bird_select_and_write_active_tun();
            if (!empty($sel['changed'])) {
                $cfg = OPNsense\Core\Config::getInstance()->object();
                $bgpOn = (string)($cfg->OPNsense->xray->general->bgp_enabled ?? '0') === '1';
                if ($bgpOn) {
                    xray_bird_active_tun_configure();
                } else {
                    echo "bird: BGP disabled — skip birdc configure (active tun files written)\n";
                }
            }
        }
    } catch (Throwable $e) {
        $resultOk = false;
        echo 'ERROR: ' . $e->getMessage() . "\n";
    }
    // Machine-readable trailer for API (ignore incidental "Error:" from birdc in the body).
    echo ($resultOk ? "RESULT:OK\n" : "RESULT:ERROR\n");
    $buf = (string)ob_get_flush();
    $idle = (bool)preg_match(
        '/auto route: (next poll in |disabled\n|plugin or BGP disabled)/',
        $buf
    );
    $work = (bool)preg_match('/\bpoll \d+\/|finalize|active tun \[|active tun selected:/', $buf);
    if ($buf !== '' && (!$idle || $work)) {
        $title = ($mode === 'autoroute') ? 'Auto route (cron)' : 'Auto route (manual / Test auto route)';
        xray_autoroute_log_block($title, $buf);
    }

    if ($mode === 'run') {
        $summary = '';
        if (preg_match('/^active tun selected:.*$/m', $buf, $m)) {
            $summary = trim($m[0]);
        } elseif (preg_match('/^active tun: plugin disabled.*$/m', $buf, $m)) {
            $summary = trim($m[0]);
        } elseif (preg_match('/^ERROR:.*$/m', $buf, $m)) {
            $summary = trim($m[0]);
        }
        if ($summary === '') {
            $summary = $resultOk ? 'Auto route OK.' : 'Auto route failed.';
        }
        $st = xray_bird_activetun_status_load();
        // Only finalize if this child still owns the token (ignore superseded runs).
        if ($runToken === '' || (string)($st['token'] ?? '') === $runToken) {
            xray_bird_activetun_status_save([
                'running'     => false,
                'phase'       => 'done',
                'result'      => $resultOk ? 'ok' : 'error',
                'message'     => $summary,
                'finished_at' => time(),
                'pid'         => 0,
            ]);
        }
    }
}
