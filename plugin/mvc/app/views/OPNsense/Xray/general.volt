<script>
    $(document).ready(function () {

        // ── Load forms ────────────────────────────────────────────────
        mapDataToFormUI({'frm_general_settings': "/api/xray/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        mapDataToFormUI({'frm_instance_settings': "/api/xray/instance/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // ── Apply ─────────────────────────────────────────────────────
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/xray/general/set", 'frm_general_settings', function () {
                    saveFormToEndpoint("/api/xray/instance/set", 'frm_instance_settings', function () {
                        dfObj.resolve();
                    });
                });
                return dfObj;
            }
        });

        // ── Status badges ─────────────────────────────────────────────
        function updateStatus() {
            ajaxGet("/api/xray/service/status", {}, function (data) {
                var xok = (data.xray_core === 'running');
                var tok = (data.tun2socks  === 'running');
                $('#badge_xray')
                    .removeClass('label-success label-danger label-default')
                    .addClass(xok ? 'label-success' : 'label-danger')
                    .text('xray-core: ' + (xok ? 'running' : 'stopped'));
                $('#badge_tun')
                    .removeClass('label-success label-danger label-default')
                    .addClass(tok ? 'label-success' : 'label-danger')
                    .text('tun2socks: ' + (tok ? 'running' : 'stopped'));

                // E2: синхронизируем состояние кнопок с реальным статусом
                var running = xok || tok;
                $('#btnStart').prop('disabled', running);
                $('#btnStop').prop('disabled', !running);
            });
        }
        updateStatus();
        setInterval(updateStatus, 5000);

        // ── E2: Start / Stop / Restart ────────────────────────────────
        function serviceAction(action, confirmMsg, callback) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            // Блокируем все три кнопки на время запроса
            var $btns = $('#btnStart, #btnStop, #btnRestart').prop('disabled', true);
            // Показываем спиннер на нажатой кнопке
            var $btn = $('#btn' + action.charAt(0).toUpperCase() + action.slice(1));
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/xray/service/' + action,
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ lang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    // Обновляем статус сразу + после задержки (демон стартует ~1с)
                    setTimeout(function () {
                        updateStatus();
                        $btns.prop('disabled', false);
                        if (callback) callback();
                    }, 1500);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        }

        $('#btnStart').click(function () {
            serviceAction('start', null, null);
        });

        $('#btnStop').click(function () {
            var confirmStop = '{{ lang._("Stop Xray VPN? Active connections will be terminated.") }}';
            serviceAction('stop', confirmStop, null);
        });

        $('#btnRestart').click(function () {
            serviceAction('restart', null, null);
        });

        // ── I8: Test Connection ───────────────────────────────────────
        $("#testConnectBtn").click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#testConnectResult');
            $res.removeClass('text-success text-danger').text("{{ lang._('Testing...') }}");

            $.ajax({
                url:      '/api/xray/service/testconnect',
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.result === 'ok') {
                        $res.addClass('text-success').text('✓ ' + data.message);
                    } else {
                        $res.addClass('text-danger').text('✗ ' + data.message);
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.addClass('text-danger').text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── I3 + E3: Log tabs ─────────────────────────────────────────
        // Общая функция загрузки лога в <pre> элемент
        // BUG-5 FIX: используем POST вместо GET — logAction/xraylogAction теперь POST-only
        function loadLog(apiEndpoint, preId, btnId) {
            $('#' + btnId).prop('disabled', true);
            $('#' + preId).text("{{ lang._('Loading...') }}");
            $.post(apiEndpoint, null, function (data) {
                var text = (data && data.log) || "{{ lang._('Log is empty.') }}";
                $('#' + preId).text(text);
                $('#' + btnId).prop('disabled', false);
                // Автоскролл вниз — последние строки видны сразу
                var pre = document.getElementById(preId);
                if (pre) { pre.scrollTop = pre.scrollHeight; }
            }, 'json').fail(function (xhr) {
                $('#' + preId).text("{{ lang._('Error loading log:') }} " + xhr.status);
                $('#' + btnId).prop('disabled', false);
            });
        }

        // Boot log (syshook, /tmp/xray_syshook.log) — загружается при входе на вкладку
        $('a[href="#logs"]').on('shown.bs.tab', function () {
            // Загружаем активную подвкладку при входе на вкладку Logs
            var $active = $('#logSubTabs .active a');
            var href = $active.attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        // Переключение подвкладок лога
        $('#logSubTabs a').on('shown.bs.tab', function (e) {
            var href = $(e.target).attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        // Кнопки Refresh для каждой подвкладки
        $("#logBootRefreshBtn").click(function () {
            loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
        });
        $("#logCoreRefreshBtn").click(function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
        });

        // ── Import VLESS ──────────────────────────────────────────────
        $("#importParseBtn").click(function () {
            var link = $.trim($("#importVlessLink").val());
            if (!link) {
                alert("{{ lang._('Paste a VLESS link first.') }}");
                return;
            }

            var $btn = $(this).prop('disabled', true);

            var b64 = btoa(unescape(encodeURIComponent(link)));

            $.ajax({
                url:         '/api/xray/import/parse',
                type:        'POST',
                contentType: 'application/json; charset=utf-8',
                data:        JSON.stringify({link_b64: b64}),
                dataType:    'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.status !== 'ok') {
                        alert("{{ lang._('Parse error:') }} " + (data.message || 'unknown'));
                        return;
                    }
                    var map = {
                        'instance.server_address':      data.host  || '',
                        'instance.server_port':         data.port  || 443,
                        'instance.uuid':                data.uuid  || '',
                        'instance.flow':                data.flow  || 'xtls-rprx-vision',
                        'instance.reality_sni':         data.sni   || '',
                        'instance.reality_pubkey':      data.pbk   || '',
                        'instance.reality_shortid':     data.sid   || '',
                        'instance.reality_fingerprint': data.fp    || 'chrome'
                    };
                    $.each(map, function (id, val) {
                        var $el = $('[id="' + id + '"]');
                        if ($el.is('select')) {
                            $el.val(val).trigger('change');
                            if ($.fn.selectpicker) { $el.selectpicker('refresh'); }
                        } else {
                            $el.val(val);
                        }
                    });
                    $("#importModal").modal('hide');
                    $('a[href="#instance"]').tab('show');
                    setTimeout(function () {
                        alert("{{ lang._('Imported! Review fields and click Apply.') }}");
                    }, 400);
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    alert("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── E5: Validate Config ─────────────────────────────────
        $('#btnValidate').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#validateResult');
            $res.removeClass('text-success text-danger').text("{{ lang._('Validating...') }}");

            $.ajax({
                url:      '/api/xray/service/validate',
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.result === 'ok') {
                        $res.removeClass('text-danger').addClass('text-success')
                            .text('✓ ' + (data.message || "{{ lang._('Config is valid') }}"));
                    } else {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text('✗ ' + (data.message || "{{ lang._('Validation failed') }}"));
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.addClass('text-danger').text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── E4: Diagnostics ──────────────────────────────────────
        function loadDiagnostics() {
            $('#btnDiagRefresh').prop('disabled', true);
            $('#diagError').hide();
            ajaxGet('/api/xray/service/diagnostics', {}, function (data) {
                $('#btnDiagRefresh').prop('disabled', false);
                if (data.error) {
                    $('#diagError').text(data.error).show();
                    return;
                }
                // Статус интерфейса с подсветкой
                var running = data.tun_status === 'running';
                var statusHtml = running
                    ? '<span class="label label-success">running</span>'
                    : '<span class="label label-danger">' + (data.tun_status || 'down') + '</span>';

                $('#diag_tun_iface').text(data.tun_interface  || '—');
                $('#diag_tun_status').html(statusHtml);
                $('#diag_tun_ip').text(data.tun_ip           || '—');
                $('#diag_mtu').text(data.mtu > 0 ? data.mtu + ' bytes' : '—');
                $('#diag_bytes_in').text(data.bytes_in_hr    || '—');
                $('#diag_bytes_out').text(data.bytes_out_hr  || '—');
                $('#diag_pkts_in').text(data.pkts_in != null ? data.pkts_in.toLocaleString() : '—');
                $('#diag_pkts_out').text(data.pkts_out != null ? data.pkts_out.toLocaleString() : '—');
                $('#diag_xray_uptime').text(data.xray_uptime || '—');
                $('#diag_t2s_uptime').text(data.tun2socks_uptime || '—');
            });
        }

        $('a[href="#diagnostics"]').on('shown.bs.tab', function () {
            loadDiagnostics();
        });
        $('#btnDiagRefresh').click(function () {
            loadDiagnostics();
        });

        // ── Tab hash ──────────────────────────────────────────────────
        if (window.location.hash !== "") {
            $('a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#instance">{{ lang._('Instance') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#diagnostics">{{ lang._('Diagnostics') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ lang._('Log') }}</a></li>
</ul>

<div class="tab-content content-box">

    <!-- INSTANCE -->
    <div id="instance" class="tab-pane fade in active">
        {# E2: панель статуса + кнопки управления сервисами #}
        <div style="padding: 10px 15px 6px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px;">
            <span id="badge_xray" class="label label-default">xray-core: ...</span>
            <span id="badge_tun"  class="label label-default">tun2socks: ...</span>

            <span style="margin-left: 4px; border-left: 1px solid #ddd; padding-left: 8px; display: inline-flex; gap: 4px;">
                {# Start — отключена если хотя бы один процесс уже запущен (синхронизировано в JS) #}
                <button id="btnStart" class="btn btn-xs btn-success" title="{{ lang._('Start Xray services') }}">
                    <i class="fa fa-play"></i> {{ lang._('Start') }}
                </button>
                {# Stop — отключена если оба процесса уже остановлены #}
                <button id="btnStop" class="btn btn-xs btn-danger" title="{{ lang._('Stop Xray services') }}">
                    <i class="fa fa-stop"></i> {{ lang._('Stop') }}
                </button>
                {# Restart — всегда доступна: останавливает всё и запускает заново без записи конфига #}
                <button id="btnRestart" class="btn btn-xs btn-warning" title="{{ lang._('Restart without saving config') }}">
                    <i class="fa fa-refresh"></i> {{ lang._('Restart') }}
                </button>
            </span>

            <span style="border-left: 1px solid #ddd; padding-left: 8px;">
                {# I8: Test Connection #}
                <button id="testConnectBtn" class="btn btn-xs btn-default" style="vertical-align: baseline;">
                    <i class="fa fa-plug"></i> {{ lang._('Test Connection') }}
                </button>
            </span>
            <span id="testConnectResult" style="font-size: 12px;"></span>
        </div>

        <div style="padding: 0 15px 8px; display: flex; gap: 6px; flex-wrap: wrap;">
            <button class="btn btn-sm btn-default" data-toggle="modal" data-target="#importModal">
                <i class="fa fa-upload"></i> {{ lang._('Import VLESS link') }}
            </button>
            {# E5: Validate Config — сухой прогон без перезапуска #}
            <button id="btnValidate" class="btn btn-sm btn-info">
                <i class="fa fa-check-circle"></i> {{ lang._('Validate Config') }}
            </button>
            <span id="validateResult" style="font-size: 12px; line-height: 30px;"></span>
        </div>
        {{ partial("layout_partials/base_form", {'fields': instanceForm, 'id': 'frm_instance_settings'}) }}
    </div>

    <!-- GENERAL -->
    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", {'fields': generalForm, 'id': 'frm_general_settings'}) }}
    </div>

    <!-- DIAGNOSTICS (E4) -->
    <div id="diagnostics" class="tab-pane fade in">
        <div style="padding: 12px 15px 4px; display: flex; align-items: center; gap: 8px;">
            <button id="btnDiagRefresh" class="btn btn-sm btn-default">
                <i class="fa fa-refresh"></i> {{ lang._('Refresh') }}
            </button>
            <span class="text-muted" style="font-size: 12px;">{{ lang._('TUN interface stats and process uptime') }}</span>
        </div>

        <div style="padding: 8px 15px 15px;">
            {# Таблица статистики #}
            <table class="table table-condensed table-striped" style="max-width: 600px;">
                <tbody>
                    <tr><th style="width:220px;">{{ lang._('TUN Interface') }}</th><td id="diag_tun_iface">—</td></tr>
                    <tr><th>{{ lang._('TUN Status') }}</th><td id="diag_tun_status">—</td></tr>
                    <tr><th>{{ lang._('TUN IP') }}</th><td id="diag_tun_ip">—</td></tr>
                    <tr><th>{{ lang._('MTU') }}</th><td id="diag_mtu">—</td></tr>
                    <tr><th>{{ lang._('Bytes In') }}</th><td id="diag_bytes_in">—</td></tr>
                    <tr><th>{{ lang._('Bytes Out') }}</th><td id="diag_bytes_out">—</td></tr>
                    <tr><th>{{ lang._('Packets In') }}</th><td id="diag_pkts_in">—</td></tr>
                    <tr><th>{{ lang._('Packets Out') }}</th><td id="diag_pkts_out">—</td></tr>
                    <tr><th>{{ lang._('xray-core Uptime') }}</th><td id="diag_xray_uptime">—</td></tr>
                    <tr><th>{{ lang._('tun2socks Uptime') }}</th><td id="diag_t2s_uptime">—</td></tr>
                </tbody>
            </table>
            <p id="diagError" class="text-danger" style="display:none;"></p>
        </div>
    </div>

    <!-- LOG (I3 + E3) -->
    <div id="logs" class="tab-pane fade in">
        {# E3: две подвкладки — Boot Log и Xray Core Log #}
        <div style="padding: 10px 15px 0;">
            <ul class="nav nav-pills" id="logSubTabs" style="margin-bottom: 0;">
                <li class="active">
                    <a data-toggle="tab" href="#logBoot">
                        <i class="fa fa-terminal"></i> {{ lang._('Boot Log') }}
                    </a>
                </li>
                <li>
                    <a data-toggle="tab" href="#logCore">
                        <i class="fa fa-file-text-o"></i> {{ lang._('Xray Core Log') }}
                    </a>
                </li>
            </ul>
        </div>

        <div class="tab-content" style="padding: 0 15px 15px;">

            {# Boot Log — /tmp/xray_syshook.log, последние 150 строк #}
            <div id="logBoot" class="tab-pane fade in active" style="padding-top: 10px;">
                <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <button id="logBootRefreshBtn" class="btn btn-sm btn-default">
                        <i class="fa fa-refresh"></i> {{ lang._('Refresh') }}
                    </button>
                    <span class="text-muted" style="font-size: 12px;">
                        {{ lang._('/tmp/xray_syshook.log — last 150 lines') }}
                    </span>
                </div>
                <pre id="logBootContent"
                     style="min-height: 300px; max-height: 550px; overflow-y: auto;
                            background: #1e1e1e; color: #d4d4d4;
                            font-family: monospace; font-size: 12px;
                            padding: 12px; border-radius: 4px; border: 1px solid #444;">{{ lang._('Switch to this tab to load log.') }}</pre>
            </div>

            {# Xray Core Log — /var/log/xray-core.log, последние 200 строк #}
            {# Лог доступен после BUG-7 fix; ротируется через newsyslog (BUG-11 fix) #}
            <div id="logCore" class="tab-pane fade in" style="padding-top: 10px;">
                <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <button id="logCoreRefreshBtn" class="btn btn-sm btn-default">
                        <i class="fa fa-refresh"></i> {{ lang._('Refresh') }}
                    </button>
                    <span class="text-muted" style="font-size: 12px;">
                        {{ lang._('/var/log/xray-core.log — last 200 lines (rotated at 600 KB)') }}
                    </span>
                </div>
                <pre id="logCoreContent"
                     style="min-height: 300px; max-height: 550px; overflow-y: auto;
                            background: #1e1e1e; color: #d4d4d4;
                            font-family: monospace; font-size: 12px;
                            padding: 12px; border-radius: 4px; border: 1px solid #444;">{{ lang._('Click "Xray Core Log" tab to load.') }}</pre>
            </div>

        </div>
    </div>

</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/xray/service/reconfigure'}) }}

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-upload"></i> {{ lang._('Import VLESS Link') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    {{ lang._('Paste your VLESS link. All Instance fields will be filled automatically.') }}
                </p>
                <input type="text"
                       id="importVlessLink"
                       class="form-control"
                       style="font-family: monospace; font-size: 12px;"
                       placeholder="vless://UUID@host:443?security=reality&pbk=...&sni=...&fp=chrome&flow=xtls-rprx-vision#Name" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="importParseBtn">
                    <i class="fa fa-magic"></i> {{ lang._('Parse & Fill') }}
                </button>
            </div>
        </div>
    </div>
</div>
