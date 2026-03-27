<script>
    $(document).ready(function () {

        // ── Helpers ───────────────────────────────────────────────
        function escAttr(s) {
            return String(s).replace(/[&"<>]/g, function (c) {
                return {'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c];
            });
        }

        // ── Per-instance status overlay ───────────────────────────
        var instanceStatusCache = {};

        function refreshInstanceStatus() {
            ajaxGet('/api/xray/service/statusall', {}, function (data) {
                if (data.error) return;
                instanceStatusCache = data;
                applyStatusToGrid();
            });
        }

        function statusBadge(info) {
            if (!info) return '<span class="label label-default" style="font-size:11px;">--</span>';
            var xOk = info.xray_core === 'running';
            var tOk = info.tun2socks === 'running';
            return '<span class="label ' + (xOk ? 'label-success' : 'label-danger') + '" style="font-size:11px;">' +
                'xray: ' + (xOk ? 'up' : 'down') +
                '</span> ' +
                '<span class="label ' + (tOk ? 'label-success' : 'label-danger') + '" style="font-size:11px;">' +
                'tun: ' + (tOk ? 'up' : 'down') +
                '</span>';
        }

        function applyStatusToGrid() {
            $('#grid-instances .xray-status-cell').each(function () {
                var uuid = $(this).data('uuid');
                $(this).html(statusBadge(instanceStatusCache[uuid]));
            });
        }

        // ── Instances CRUD table (UIBootgrid) ───────────────────────
        $('#grid-instances').UIBootgrid({
            search: '/api/xray/instance/searchItem',
            get:    '/api/xray/instance/getItem/',
            set:    '/api/xray/instance/setItem/',
            add:    '/api/xray/instance/addItem',
            del:    '/api/xray/instance/delItem/',
            options: {
                formatters: {
                    instanceStatus: function (column, row) {
                        return '<span class="xray-status-cell" data-uuid="' + escAttr(row.uuid) + '">' +
                            statusBadge(instanceStatusCache[row.uuid]) + '</span>';
                    }
                }
            }
        });

        // After grid loads/reloads data, fetch and overlay status
        $('#grid-instances').on('loaded.rs.jquery.bootgrid', function () {
            refreshInstanceStatus();
        });

        // ── General settings form ───────────────────────────────────
        mapDataToFormUI({'frm_general_settings': "/api/xray/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // ── Config Mode toggle (wizard ↔ custom) in dialog ─────────
        function toggleConfigMode() {
            var mode = $('#instance\\.config_mode').val();
            var $wizardHeaders = $('#DialogInstance td[colspan="2"] b').filter(function () {
                var t = $(this).text().trim();
                return t === 'Server' || t === 'Reality Settings';
            }).closest('tr');
            var wizardFields = [
                'instance.server_address', 'instance.server_port', 'instance.vless_uuid',
                'instance.flow', 'instance.reality_sni', 'instance.reality_pubkey',
                'instance.reality_shortid', 'instance.reality_fingerprint'
            ];
            var $customConfig = $('#DialogInstance [id="instance.custom_config"]').closest('tr');

            if (mode === 'custom') {
                $.each(wizardFields, function (_, fieldId) {
                    $('#DialogInstance [id="' + fieldId + '"]').closest('tr').hide();
                });
                $wizardHeaders.hide();
                $customConfig.show();
            } else {
                $.each(wizardFields, function (_, fieldId) {
                    $('#DialogInstance [id="' + fieldId + '"]').closest('tr').show();
                });
                $wizardHeaders.show();
                $customConfig.hide();
            }
        }

        // Toggle on mode change
        $(document).on('change', '#instance\\.config_mode', function () {
            toggleConfigMode();
        });

        // Toggle when dialog opens — poll until mapDataToFormUI finishes loading data
        $('#DialogInstance').on('shown.bs.modal', function () {
            var attempts = 0;
            var poller = setInterval(function () {
                attempts++;
                var mode = $('#instance\\.config_mode').val();
                if (mode === 'wizard' || mode === 'custom' || attempts > 20) {
                    clearInterval(poller);
                    toggleConfigMode();
                }
            }, 100);
        });

        // ── Apply (save general, then reconfigure) ──────────────────
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                var dfObj = new $.Deferred();
                saveFormToEndpoint("/api/xray/general/set", 'frm_general_settings', function () {
                    dfObj.resolve();
                });
                return dfObj;
            }
        });

        // ── Status badges + per-instance status ───────────────────
        function updateStatus() {
            ajaxGet("/api/xray/service/statusall", {}, function (data) {
                if (data.error) return;
                instanceStatusCache = data;

                // Aggregate: any instance running = global running
                var anyXray = false, anyTun = false;
                $.each(data, function (uuid, info) {
                    if (info.xray_core === 'running') anyXray = true;
                    if (info.tun2socks === 'running') anyTun = true;
                });
                var xok = anyXray, tok = anyTun;
                $('#badge_xray')
                    .removeClass('label-success label-danger label-default')
                    .addClass(xok ? 'label-success' : 'label-danger')
                    .text('xray-core: ' + (xok ? 'running' : 'stopped'));
                $('#badge_tun')
                    .removeClass('label-success label-danger label-default')
                    .addClass(tok ? 'label-success' : 'label-danger')
                    .text('tun2socks: ' + (tok ? 'running' : 'stopped'));

                // Update per-instance status in grid
                applyStatusToGrid();

                var running = xok || tok;
                $('#btnStart').prop('disabled', running);
                $('#btnStop').prop('disabled', !running);
            });
        }
        updateStatus();
        setInterval(updateStatus, 5000);

        // ── Start / Stop / Restart ──────────────────────────────────
        function serviceAction(action, confirmMsg, callback) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            var $btns = $('#btnStart, #btnStop, #btnRestart').prop('disabled', true);
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

        // ── Test Connection ─────────────────────────────────────────
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
                        $res.addClass('text-success').text(data.message);
                    } else {
                        $res.addClass('text-danger').text(data.message);
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.addClass('text-danger').text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── Import VLESS (inside DialogInstance) ──────────────────
        function applyImportToDialog(data) {
            var $dlg = $('#DialogInstance');
            var $modeSelect = $dlg.find('#instance\\.config_mode');

            // Always set server/port for table display regardless of mode
            $dlg.find('[id="instance.server_address"]').val(data.host || '');
            $dlg.find('[id="instance.server_port"]').val(data.port || 443);

            if (data.config_mode === 'custom') {
                $modeSelect.val('custom').trigger('change');
                if ($.fn.selectpicker) { $modeSelect.selectpicker('refresh'); }
                $dlg.find('[id="instance.custom_config"]').val(data.custom_config || '');
            } else {
                var map = {
                    'instance.server_address':      data.host  || '',
                    'instance.server_port':         data.port  || 443,
                    'instance.vless_uuid':          data.vless_uuid || '',
                    'instance.flow':                data.flow  || 'xtls-rprx-vision',
                    'instance.reality_sni':         data.sni   || '',
                    'instance.reality_pubkey':      data.pbk   || '',
                    'instance.reality_shortid':     data.sid   || '',
                    'instance.reality_fingerprint': data.fp    || 'chrome'
                };
                $.each(map, function (id, val) {
                    var $el = $dlg.find('[id="' + id + '"]');
                    if ($el.is('select')) {
                        $el.val(val).trigger('change');
                        if ($.fn.selectpicker) { $el.selectpicker('refresh'); }
                    } else {
                        $el.val(val);
                    }
                });
                $modeSelect.val('wizard').trigger('change');
                if ($.fn.selectpicker) { $modeSelect.selectpicker('refresh'); }
            }
            // Set name from link fragment if available
            if (data.name) {
                $dlg.find('[id="instance.name"]').val(data.name);
            }
            toggleConfigMode();
        }

        // Inject Import panel + Validate button into DialogInstance on first open
        var dialogInjected = false;
        $('#DialogInstance').on('show.bs.modal', function () {
            if (dialogInjected) {
                // Reset state on each open
                $('#dlgImportLink').val('');
                $('#dlgImportResult').text('').removeClass('text-success text-danger');
                $('#dlgImportPanel').collapse('hide');
                $('#dlgValidateResult').text('').removeClass('text-success text-danger');
                return;
            }
            dialogInjected = true;

            // Import panel — collapsible, injected before the form table
            var importHtml =
                '<div style="margin: 0 0 10px;">' +
                    '<a data-toggle="collapse" href="#dlgImportPanel" class="btn btn-sm btn-default" style="margin-bottom: 6px;">' +
                        '<i class="fa fa-upload"></i> {{ lang._("Import VLESS link") }}' +
                    '</a>' +
                    '<div id="dlgImportPanel" class="collapse">' +
                        '<div class="well well-sm" style="margin-bottom: 0;">' +
                            '<div class="input-group">' +
                                '<input type="text" id="dlgImportLink" class="form-control input-sm"' +
                                '  style="font-family: monospace; font-size: 12px;"' +
                                '  placeholder="vless://UUID@host:443?security=reality&pbk=...#Name" />' +
                                '<span class="input-group-btn">' +
                                    '<button type="button" id="dlgImportParseBtn" class="btn btn-sm btn-primary">' +
                                        '<i class="fa fa-magic"></i> {{ lang._("Parse & Fill") }}' +
                                    '</button>' +
                                '</span>' +
                            '</div>' +
                            '<span id="dlgImportResult" style="font-size: 12px; display: inline-block; margin-top: 4px;"></span>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            var $body = $(this).find('.modal-body');
            $body.prepend(importHtml);

            // Validate button — in footer, before Save
            var validateHtml =
                '<button type="button" id="dlgValidateBtn" class="btn btn-info pull-left">' +
                    '<i class="fa fa-check-circle"></i> {{ lang._("Validate Config") }}' +
                '</button>' +
                '<span id="dlgValidateResult" class="pull-left" style="font-size: 12px; line-height: 34px; margin-left: 8px;"></span>';
            var $footer = $(this).find('.modal-footer');
            $footer.prepend(validateHtml);
        });

        // Import parse handler (inside dialog)
        $(document).on('click', '#dlgImportParseBtn', function () {
            var link = $.trim($('#dlgImportLink').val());
            var $res = $('#dlgImportResult');
            if (!link) {
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Paste a VLESS link first.') }}");
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $res.removeClass('text-success text-danger').text("{{ lang._('Parsing...') }}");
            var b64 = btoa(unescape(encodeURIComponent(link)));

            // Send current SOCKS5 settings from form so custom config uses them
            var $dlg = $('#DialogInstance');
            var socksListen = $dlg.find('[id="instance.socks5_listen"]').val() || '127.0.0.1';
            var socksPort = parseInt($dlg.find('[id="instance.socks5_port"]').val(), 10) || 10808;

            $.ajax({
                url:         '/api/xray/import/parse',
                type:        'POST',
                contentType: 'application/json; charset=utf-8',
                data:        JSON.stringify({link_b64: b64, socks5_listen: socksListen, socks5_port: socksPort}),
                dataType:    'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.status !== 'ok') {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text("{{ lang._('Parse error:') }} " + (data.message || 'unknown'));
                        return;
                    }
                    applyImportToDialog(data);
                    $res.removeClass('text-danger').addClass('text-success')
                        .text("{{ lang._('Imported! Fields filled from link.') }}");
                    // Collapse import panel after success
                    setTimeout(function () { $('#dlgImportPanel').collapse('hide'); }, 1500);
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.removeClass('text-success').addClass('text-danger')
                        .text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // Enter key in import field triggers parse
        $(document).on('keypress', '#dlgImportLink', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#dlgImportParseBtn').click();
            }
        });

        // ── Validate Config (inside DialogInstance footer) ────────
        $(document).on('click', '#dlgValidateBtn', function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#dlgValidateResult');
            $res.removeClass('text-success text-danger').text("{{ lang._('Validating...') }}");

            $.ajax({
                url:      '/api/xray/service/validate',
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.result === 'ok') {
                        $res.removeClass('text-danger').addClass('text-success')
                            .text(data.message || "{{ lang._('Config is valid') }}");
                    } else {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text(data.message || "{{ lang._('Validation failed') }}");
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.addClass('text-danger').text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── Diagnostics ─────────────────────────────────────────────
        function loadDiagnostics() {
            $('#btnDiagRefresh').prop('disabled', true);
            $('#diagError').hide();
            ajaxGet('/api/xray/service/diagnostics', {}, function (data) {
                $('#btnDiagRefresh').prop('disabled', false);
                if (data.error) {
                    $('#diagError').text(data.error).show();
                    return;
                }
                var running = data.tun_status === 'running';
                var statusHtml = running
                    ? '<span class="label label-success">running</span>'
                    : '<span class="label label-danger">' + escAttr(data.tun_status || 'down') + '</span>';

                $('#diag_tun_iface').text(data.tun_interface  || '\u2014');
                $('#diag_tun_status').html(statusHtml);
                $('#diag_tun_ip').text(data.tun_ip           || '\u2014');
                $('#diag_mtu').text(data.mtu > 0 ? data.mtu + ' bytes' : '\u2014');
                $('#diag_bytes_in').text(data.bytes_in_hr    || '\u2014');
                $('#diag_bytes_out').text(data.bytes_out_hr  || '\u2014');
                $('#diag_pkts_in').text(data.pkts_in != null ? data.pkts_in.toLocaleString() : '\u2014');
                $('#diag_pkts_out').text(data.pkts_out != null ? data.pkts_out.toLocaleString() : '\u2014');
                $('#diag_xray_uptime').text(data.xray_uptime || '\u2014');
                $('#diag_t2s_uptime').text(data.tun2socks_uptime || '\u2014');
                $('#diag_ping_rtt').text(data.ping_rtt || 'N/A');
            });
        }

        var diagAutoRefresh = null;
        $('a[href="#diagnostics"]').on('shown.bs.tab', function () {
            loadDiagnostics();
            if (!diagAutoRefresh) {
                diagAutoRefresh = setInterval(function () {
                    if ($('#diagnostics').hasClass('active')) {
                        loadDiagnostics();
                    }
                }, 30000);
            }
        });
        $('#btnDiagRefresh').click(function () {
            loadDiagnostics();
        });

        // ── Logs ────────────────────────────────────────────────────
        function loadLog(apiEndpoint, preId, btnId) {
            $('#' + btnId).prop('disabled', true);
            $('#' + preId).text("{{ lang._('Loading...') }}");
            $.post(apiEndpoint, null, function (data) {
                var text = (data && data.log) || "{{ lang._('Log is empty.') }}";
                $('#' + preId).text(text);
                $('#' + btnId).prop('disabled', false);
                var pre = document.getElementById(preId);
                if (pre) { pre.scrollTop = pre.scrollHeight; }
            }, 'json').fail(function (xhr) {
                $('#' + preId).text("{{ lang._('Error loading log:') }} " + xhr.status);
                $('#' + btnId).prop('disabled', false);
            });
        }

        $('a[href="#logs"]').on('shown.bs.tab', function () {
            var $active = $('#logSubTabs .active a');
            var href = $active.attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        $('#logSubTabs a').on('shown.bs.tab', function (e) {
            var href = $(e.target).attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        $("#logBootRefreshBtn").click(function () {
            loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
        });
        $("#logCoreRefreshBtn").click(function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
        });

        // ── Copy Debug Info ─────────────────────────────────────────
        $('#btnCopyDebug').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#copyDebugResult');
            $res.removeClass('text-success text-danger').text("{{ lang._('Collecting...') }}");

            var diagData = {}, bootLog = '', coreLog = '';
            var diagDone = $.Deferred(), bootDone = $.Deferred(), coreDone = $.Deferred();

            ajaxGet('/api/xray/service/diagnostics', {}, function (data) {
                diagData = data;
                diagDone.resolve();
            });
            $.post('/api/xray/service/log', null, function (data) {
                bootLog = (data && data.log) || '';
                bootDone.resolve();
            }, 'json').fail(function () { bootDone.resolve(); });
            $.post('/api/xray/service/xraylog', null, function (data) {
                coreLog = (data && data.log) || '';
                coreDone.resolve();
            }, 'json').fail(function () { coreDone.resolve(); });

            $.when(diagDone, bootDone, coreDone).done(function () {
                var info = "=== os-xray Debug Info ===\n"
                    + "Date: " + new Date().toISOString() + "\n\n"
                    + "--- Diagnostics ---\n"
                    + JSON.stringify(diagData, null, 2) + "\n\n"
                    + "--- Boot Log (last 150 lines) ---\n"
                    + bootLog + "\n\n"
                    + "--- Core Log (last 200 lines) ---\n"
                    + coreLog + "\n";

                $('#debugInfoContent').val(info);
                $('#debugInfoModal').modal('show');
                $('#debugInfoModal').one('shown.bs.modal', function () {
                    var ta = document.getElementById('debugInfoContent');
                    ta.focus();
                    ta.select();
                });
                $res.addClass('text-success').text("{{ lang._('Use Ctrl+C / Cmd+C to copy') }}");
                $btn.prop('disabled', false);
            });
        });

        // ── Tab hash ────────────────────────────────────────────────
        if (window.location.hash !== "") {
            $('a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#instances">{{ lang._('Instances') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#diagnostics">{{ lang._('Diagnostics') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ lang._('Log') }}</a></li>
</ul>

<div class="tab-content content-box">

    <!-- INSTANCES -->
    <div id="instances" class="tab-pane fade in active">
        {# Status bar + service controls #}
        <div style="padding: 10px 15px 6px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px;">
            <span id="badge_xray" class="label label-default">xray-core: ...</span>
            <span id="badge_tun"  class="label label-default">tun2socks: ...</span>

            <span style="margin-left: 4px; border-left: 1px solid #ddd; padding-left: 8px; display: inline-flex; gap: 4px;">
                <button id="btnStart" class="btn btn-xs btn-success" title="{{ lang._('Start all instances') }}">
                    <i class="fa fa-play"></i> {{ lang._('Start') }}
                </button>
                <button id="btnStop" class="btn btn-xs btn-danger" title="{{ lang._('Stop all instances') }}">
                    <i class="fa fa-stop"></i> {{ lang._('Stop') }}
                </button>
                <button id="btnRestart" class="btn btn-xs btn-warning" title="{{ lang._('Restart without saving config') }}">
                    <i class="fa fa-refresh"></i> {{ lang._('Restart') }}
                </button>
            </span>

            <span style="border-left: 1px solid #ddd; padding-left: 8px;">
                <button id="testConnectBtn" class="btn btn-xs btn-default" style="vertical-align: baseline;">
                    <i class="fa fa-plug"></i> {{ lang._('Test Connection') }}
                </button>
            </span>
            <span id="testConnectResult" style="font-size: 12px;"></span>
        </div>


        {# Bootgrid instances table #}
        <table id="grid-instances" class="table table-condensed table-hover table-striped"
               data-editDialog="DialogInstance" data-editAlert="InstanceChangeMessage">
            <thead>
                <tr>
                    <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._('ID') }}</th>
                    <th data-column-id="name" data-type="string">{{ lang._('Name') }}</th>
                    <th data-column-id="server_address" data-type="string">{{ lang._('Server') }}</th>
                    <th data-column-id="server_port" data-type="string" data-width="80px">{{ lang._('Port') }}</th>
                    <th data-column-id="config_mode" data-type="string" data-width="100px">{{ lang._('Mode') }}</th>
                    <th data-column-id="inst_status" data-formatter="instanceStatus" data-sortable="false" data-width="180px">{{ lang._('Status') }}</th>
                    <th data-column-id="commands" data-formatter="commands" data-sortable="false" data-width="120px">{{ lang._('') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td>
                        <button data-action="add" type="button" class="btn btn-xs btn-primary">
                            <span class="fa fa-fw fa-plus"></span>
                        </button>
                        <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default">
                            <span class="fa fa-fw fa-trash-o"></span>
                        </button>
                    </td>
                </tr>
            </tfoot>
        </table>
        <div id="InstanceChangeMessage" class="alert alert-info" style="display: none;" role="alert">
            {{ lang._('Changes saved. Click Apply below to activate.') }}
        </div>
    </div>

    <!-- GENERAL -->
    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", {'fields': generalForm, 'id': 'frm_general_settings'}) }}
    </div>

    <!-- DIAGNOSTICS -->
    <div id="diagnostics" class="tab-pane fade in">
        <div style="padding: 12px 15px 4px; display: flex; align-items: center; gap: 8px;">
            <button id="btnDiagRefresh" class="btn btn-sm btn-default">
                <i class="fa fa-refresh"></i> {{ lang._('Refresh') }}
            </button>
            <button id="btnCopyDebug" class="btn btn-sm btn-default">
                <i class="fa fa-clipboard"></i> {{ lang._('Copy Debug Info') }}
            </button>
            <span id="copyDebugResult" style="font-size: 12px;"></span>
            <span class="text-muted" style="font-size: 12px;">{{ lang._('TUN interface stats and process uptime') }}</span>
        </div>

        <div style="padding: 8px 15px 15px;">
            <table class="table table-condensed table-striped" style="max-width: 600px;">
                <tbody>
                    <tr><th style="width:220px;">{{ lang._('TUN Interface') }}</th><td id="diag_tun_iface">&mdash;</td></tr>
                    <tr><th>{{ lang._('TUN Status') }}</th><td id="diag_tun_status">&mdash;</td></tr>
                    <tr><th>{{ lang._('TUN IP') }}</th><td id="diag_tun_ip">&mdash;</td></tr>
                    <tr><th>{{ lang._('MTU') }}</th><td id="diag_mtu">&mdash;</td></tr>
                    <tr><th>{{ lang._('Bytes In') }}</th><td id="diag_bytes_in">&mdash;</td></tr>
                    <tr><th>{{ lang._('Bytes Out') }}</th><td id="diag_bytes_out">&mdash;</td></tr>
                    <tr><th>{{ lang._('Packets In') }}</th><td id="diag_pkts_in">&mdash;</td></tr>
                    <tr><th>{{ lang._('Packets Out') }}</th><td id="diag_pkts_out">&mdash;</td></tr>
                    <tr><th>{{ lang._('xray-core Uptime') }}</th><td id="diag_xray_uptime">&mdash;</td></tr>
                    <tr><th>{{ lang._('tun2socks Uptime') }}</th><td id="diag_t2s_uptime">&mdash;</td></tr>
                    <tr><th>{{ lang._('Server Ping RTT') }}</th><td id="diag_ping_rtt">&mdash;</td></tr>
                </tbody>
            </table>
            <p id="diagError" class="text-danger" style="display:none;"></p>
        </div>
    </div>

    <!-- LOGS -->
    <div id="logs" class="tab-pane fade in">
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

{# Instance edit/add dialog (used by UIBootgrid) #}
{{ partial("layout_partials/base_dialog",['fields':instanceForm,'id':'DialogInstance','label':lang._('Edit Instance')]) }}


<!-- Debug Info Modal -->
<div class="modal fade" id="debugInfoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-clipboard"></i> {{ lang._('Debug Info') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    {{ lang._('Select all (Ctrl+A / Cmd+A) and copy (Ctrl+C / Cmd+C), then paste into your issue report.') }}
                </p>
                <textarea id="debugInfoContent" readonly cols="1000"
                          style="font-family: monospace; font-size: 11px; width: 100% !important; min-width: 100% !important; max-width: 100% !important; height: 70vh; resize: vertical; background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 4px; border: 1px solid #444; display: block; box-sizing: border-box; white-space: pre; overflow-x: auto;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>
