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
            });
        }
        updateStatus();
        setInterval(updateStatus, 10000);

        // ── Import VLESS ──────────────────────────────────────────────
        $("#importParseBtn").click(function () {
            var link = $.trim($("#importVlessLink").val());
            if (!link) {
                alert("{{ lang._('Paste a VLESS link first.') }}");
                return;
            }

            var $btn = $(this).prop('disabled', true);

            // Кодируем ссылку в base64 чтобы & внутри не ломал передачу.
            // btoa() работает только с Latin-1, поэтому сначала encodeURIComponent.
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
                    // Заполняем поля формы Instance
                    // OPNsense base_form рендерит input с id == field id из forms/instance.xml
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
                        // id содержит точку — используем атрибутный селектор
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
</ul>

<div class="tab-content content-box">

    <!-- INSTANCE -->
    <div id="instance" class="tab-pane fade in active">
        <div style="padding: 10px 15px 4px;">
            <span id="badge_xray" class="label label-default">xray-core: ...</span>
            &nbsp;
            <span id="badge_tun" class="label label-default">tun2socks: ...</span>
        </div>
        <div style="padding: 4px 15px 8px;">
            <button class="btn btn-sm btn-default" data-toggle="modal" data-target="#importModal">
                <i class="fa fa-upload"></i> {{ lang._('Import VLESS link') }}
            </button>
        </div>
        {{ partial("layout_partials/base_form", ['fields': instanceForm, 'id': 'frm_instance_settings']) }}
    </div>

    <!-- GENERAL -->
    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general_settings']) }}
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
