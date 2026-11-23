<script>
    $(document).ready(function () {
        // ── Helpers ───────────────────────────────────────────────
        function escAttr(s) {
            return String(s).replace(/[&"<>]/g, function (c) {
                return {'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c];
            });
        }

        function xrayHelpIsOn() {
            return !!(window.sessionStorage && sessionStorage.getItem('all_help_preset') === '1');
        }

        function xrayHelpClasses() {
            return xrayHelpIsOn() ? 'show' : 'hidden';
        }

        function xraySyncHelp() {
            var on = xrayHelpIsOn();
            $('[data-for*="help_for"]').toggleClass('show', on).toggleClass('hidden', !on);
            $('.xray-full-help-toggle')
                .toggleClass('fa-toggle-on', on).toggleClass('fa-toggle-off', !on)
                .toggleClass('text-success', on).toggleClass('text-danger', !on);
            $('[id*="show_all_help"]')
                .toggleClass('fa-toggle-on', on).toggleClass('fa-toggle-off', !on)
                .toggleClass('text-success', on).toggleClass('text-danger', !on);
        }

        $(document).on('click', '.xray-full-help', function (e) {
            e.preventDefault();
            if (window.sessionStorage) {
                sessionStorage.setItem('all_help_preset', xrayHelpIsOn() ? '0' : '1');
            }
            xraySyncHelp();
        });
        $(document).on('click', 'a.xray-showhelp', function (e) {
            e.preventDefault();
            $("*[data-for='" + $(this).attr('id') + "']").toggleClass('hidden show');
        });
        $(document).on('click', '[id*="show_all_help"]', function () {
            setTimeout(xraySyncHelp, 0);
        });
        setTimeout(xraySyncHelp, 0);

        // ── Per-instance status overlay ───────────────────────────
        var instanceStatusCache = {};
        var instanceTestCache   = {};

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

        function fmtLatencySec(t) {
            var n = Number(t);
            if (!isFinite(n) || n < 0) {
                n = 0;
            }
            return n.toFixed(3) + 's';
        }

        /**
         * Active TUN latency cell (like Status labels):
         *  green  — this instance is the active TUN (show RTT)
         *  red    — no probe response (show 0)
         *  yellow — has RTT but not active: time less than active TUN, or otherwise not selected
         */
        function activeTunBadge(lat) {
            if (!lat) {
                return '<span class="label label-default" style="font-size:11px;">--</span>';
            }
            if (!lat.ok) {
                return '<span class="label label-danger" style="font-size:11px;">0</span>';
            }
            var time = Number(lat.time);
            if (!isFinite(time) || time < 0) {
                time = 0;
            }
            var text = fmtLatencySec(time);
            if (lat.active) {
                return '<span class="label label-success" style="font-size:11px;">' + escAttr(text) + '</span>';
            }
            // Not active: yellow when RTT is lower than active's (weight) or any non-winner with a reply
            return '<span class="label label-warning" style="font-size:11px;">' + escAttr(text) + '</span>';
        }

        function gridRowUuid(el) {
            return String($(el).attr('data-uuid') || $(el).data('uuid') || '');
        }

        function applyStatusToGrid() {
            $('#grid-instances .xray-status-cell').each(function () {
                var uuid = gridRowUuid(this);
                $(this).html(statusBadge(instanceStatusCache[uuid]));
            });
            $('#grid-instances .xray-activetun-v4-cell').each(function () {
                var uuid = gridRowUuid(this);
                var info = instanceStatusCache[uuid];
                $(this).html(activeTunBadge(info && info.active_tun_v4));
            });
            $('#grid-instances .xray-activetun-v6-cell').each(function () {
                var uuid = gridRowUuid(this);
                var info = instanceStatusCache[uuid];
                $(this).html(activeTunBadge(info && info.active_tun_v6));
            });
        }

        function testResultBadge(info) {
            if (!info) return '<span style="font-size:11px;color:#999;">--</span>';
            var ok = info.result === 'ok';
            return '<span class="label ' + (ok ? 'label-success' : 'label-danger') + '" style="font-size:11px;">'
                + escAttr(info.message) + '</span>';
        }

        function applyTestResultToGrid() {
            $('#grid-instances .xray-test-cell').each(function () {
                var uuid = gridRowUuid(this);
                $(this).html(testResultBadge(instanceTestCache[uuid]));
            });
        }

        // ── Instances CRUD table (UIBootgrid) ───────────────────────
        $('#grid-instances').UIBootgrid({
            search: '/api/xray/instance/searchItem',
            get:    '/api/xray/instance/getItem/',
            set:    '/api/xray/instance/setItem/',
            add:    '/api/xray/instance/addItem',
            del:    '/api/xray/instance/delItem/',
            toggle: '/api/xray/instance/toggleItem/',
            options: {
                formatters: {
                    instanceStatus: function (column, row) {
                        // TODO: show "disabled" label instead of status badges when row.enabled === '0'
                        return '<span class="xray-status-cell" data-uuid="' + escAttr(row.uuid) + '">' +
                            statusBadge(instanceStatusCache[row.uuid]) + '</span>';
                    },
                    instanceActiveTunV4: function (column, row) {
                        var info = instanceStatusCache[row.uuid];
                        return '<span class="xray-activetun-v4-cell" data-uuid="' + escAttr(row.uuid) + '">'
                            + activeTunBadge(info && info.active_tun_v4) + '</span>';
                    },
                    instanceActiveTunV6: function (column, row) {
                        var info = instanceStatusCache[row.uuid];
                        return '<span class="xray-activetun-v6-cell" data-uuid="' + escAttr(row.uuid) + '">'
                            + activeTunBadge(info && info.active_tun_v6) + '</span>';
                    },
                    instanceTestResult: function (column, row) {
                        return '<span class="xray-test-cell" data-uuid="' + escAttr(row.uuid) + '">'
                            + testResultBadge(instanceTestCache[row.uuid]) + '</span>';
                    },
                    commands: function (column, row) {
                        // TODO: disable/hide cmd-inst-start and cmd-inst-stop when row.enabled === '0'
                        var uuid = escAttr(row.uuid);
                        return '<button type="button" class="btn btn-xs btn-success cmd-inst-start bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Start this instance") }}">'
                             +   '<span class="fa fa-play fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-danger cmd-inst-stop bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Stop this instance") }}">'
                             +   '<span class="fa fa-stop fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-default cmd-inst-test bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Test") }}">'
                             +   '<span class="fa fa-plug fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-default command-edit bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Edit") }}">'
                             +   '<span class="fa fa-pencil fa-fw"></span></button> '
                             + '<button type="button" class="btn btn-xs btn-default command-delete bootgrid-tooltip"'
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Delete") }}">'
                             +   '<span class="fa fa-trash-o fa-fw"></span></button>';
                    }
                }
            }
        });

        // After grid loads/reloads data, fetch and overlay status
        $('#grid-instances').on('loaded.rs.jquery.bootgrid', function () {
            refreshInstancesStatus();
            populateInstanceSelects();
        });

        // Per-instance start / stop / test (row button handlers)
        $(document).on('click', '#grid-instances .cmd-inst-start', function () {
            instanceServiceAction('start', $(this).data('row-id'));
        });
        $(document).on('click', '#grid-instances .cmd-inst-stop', function () {
            instanceServiceAction('stop', $(this).data('row-id'));
        });
        $(document).on('click', '#grid-instances .cmd-inst-test', function () {
            var uuid = $(this).data('row-id');
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '/api/xray/service/testconnect/' + encodeURIComponent(uuid),
                type: 'POST', dataType: 'json',
                success: function (data) {
                    instanceTestCache[uuid] = data;
                    applyTestResultToGrid();
                },
                complete: function () { $btn.prop('disabled', false); }
            });
        });

        function instanceServiceAction(action, uuid) {
            var url = '/api/xray/service/' + action + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $.ajax({
                url: url, type: 'POST', dataType: 'json',
                success: function () { setTimeout(refreshInstancesStatus, 1500); }
            });
        }

        // ── BGP peers / filters / communities CRUD ──────────────────
        var peerStatusCache = {};
        var birdRunning = false;

        function peerStatusBadge(info) {
            if (!info) {
                return '<span class="label label-default" style="font-size:11px;">--</span>';
            }
            var state = String(info.state || '—');
            var ok = state === 'up' || String(info.info || '').toLowerCase() === 'established';
            var cls = state === 'disabled' ? 'label-default'
                    : (ok ? 'label-success' : 'label-danger');
            return '<span class="label ' + cls + '" style="font-size:11px;">' + escAttr(state) + '</span> '
                + '<span style="font-size:11px;">' + escAttr(info.info || '—') + '</span>';
        }

        function peerFamilyEnabled(row, family) {
            if (!row) {
                return true;
            }
            var v = row[family];
            return !(v === '0' || v === 0 || v === false);
        }

        function peerPrefixBadge(info, family, familyOn) {
            var n = 0;
            if (info) {
                n = family === 'ipv6' ? info.imported6 : info.imported4;
                if (n == null) {
                    n = 0;
                }
            }
            var liveOn = !!(info && (family === 'ipv6' ? info.ipv6 : info.ipv4));
            if (!familyOn && !liveOn && !(Number(n) > 0)) {
                return '<span style="font-size:11px;color:#999;">—</span>';
            }
            if (!info) {
                return '<span class="label label-default" style="font-size:11px;">--</span>';
            }
            return '<span class="label label-info" style="font-size:11px;" title="{{ lang._("Imported prefixes") }}">'
                + escAttr(String(n)) + '</span>';
        }

        function applyPeerStatusToGrid() {
            $('#grid-bgppeers .xray-peer-status-cell').each(function () {
                var uuid = $(this).attr('data-uuid');
                $(this).html(peerStatusBadge(peerStatusCache[uuid]));
            });
            $('#grid-bgppeers .xray-peer-v4-cell').each(function () {
                var uuid = $(this).attr('data-uuid');
                var on = String($(this).attr('data-family-on')) !== '0';
                $(this).html(peerPrefixBadge(peerStatusCache[uuid], 'ipv4', on));
            });
            $('#grid-bgppeers .xray-peer-v6-cell').each(function () {
                var uuid = $(this).attr('data-uuid');
                var on = String($(this).attr('data-family-on')) !== '0';
                $(this).html(peerPrefixBadge(peerStatusCache[uuid], 'ipv6', on));
            });
        }

        function updateBirdToolbar() {
            $('.xray-badge-bird')
                .removeClass('label-success label-danger label-default')
                .addClass(birdRunning ? 'label-success' : 'label-danger')
                .text('bird: ' + (birdRunning ? 'running' : 'stopped'));
            $('.xray-btn-bird-testall').prop('disabled', !birdRunning);
        }

        function applyPeerStatusPayload(data) {
            if (!data || data.error) {
                return false;
            }
            birdRunning = !!data.running;
            peerStatusCache = birdRunning ? (data.peers || {}) : {};
            applyPeerStatusToGrid();
            updateBirdToolbar();
            return true;
        }

        function openRoutingPeersStatus() {
            ajaxGet("/api/xray/bgppeer/statusAll", {}, function (data) {
                if (!data || data.error) {
                    birdRunning = false;
                    peerStatusCache = {};
                    applyPeerStatusToGrid();
                    updateBirdToolbar();
                    return;
                }
                birdRunning = !!data.running;
                peerStatusCache = birdRunning ? (data.peers || {}) : {};
                applyPeerStatusToGrid();
                updateBirdToolbar();
            });
        }

        $('#grid-bgppeers').UIBootgrid({
            search: '/api/xray/bgppeer/searchItem',
            get:    '/api/xray/bgppeer/getItem/',
            set:    '/api/xray/bgppeer/setItem/',
            add:    '/api/xray/bgppeer/addItem',
            del:    '/api/xray/bgppeer/delItem/',
            toggle: '/api/xray/bgppeer/toggleItem/',
            options: {
                formatters: {
                    peerPrefixes4: function (column, row) {
                        var on = peerFamilyEnabled(row, 'ipv4');
                        return '<span class="xray-peer-v4-cell" data-uuid="' + escAttr(row.uuid)
                            + '" data-family-on="' + (on ? '1' : '0') + '">'
                            + peerPrefixBadge(peerStatusCache[row.uuid], 'ipv4', on) + '</span>';
                    },
                    peerPrefixes6: function (column, row) {
                        var on = peerFamilyEnabled(row, 'ipv6');
                        return '<span class="xray-peer-v6-cell" data-uuid="' + escAttr(row.uuid)
                            + '" data-family-on="' + (on ? '1' : '0') + '">'
                            + peerPrefixBadge(peerStatusCache[row.uuid], 'ipv6', on) + '</span>';
                    },
                    peerStatus: function (column, row) {
                        return '<span class="xray-peer-status-cell" data-uuid="' + escAttr(row.uuid) + '">'
                            + peerStatusBadge(peerStatusCache[row.uuid]) + '</span>';
                    }
                }
            }
        });

        function reloadBgpPeersGrid() {
            var $grid = $('#grid-bgppeers');
            if ($grid.length && $grid.data('bootgrid')) {
                $grid.bootgrid('reload');
            }
        }

        $('#grid-bgppeers').on('loaded.rs.jquery.bootgrid', function () {
            var $tbody = $(this).find('tbody');
            $tbody.find('tr').each(function () {
                var $tr = $(this);
                var isDnstap = $tr.children('td').filter(function () {
                    return $.trim($(this).text()) === 'dnstap';
                }).length > 0;
                if (isDnstap) {
                    $tbody.prepend($tr);
                    return false;
                }
            });
            applyPeerStatusToGrid();
        });

        $('#grid-bgpfilters').UIBootgrid({
            search: '/api/xray/bgpfilter/searchItem',
            get:    '/api/xray/bgpfilter/getItem/',
            set:    '/api/xray/bgpfilter/setItem/',
            add:    '/api/xray/bgpfilter/addItem',
            del:    '/api/xray/bgpfilter/delItem/',
            toggle: '/api/xray/bgpfilter/toggleItem/'
        });
        $('#grid-bgpcommunities').UIBootgrid({
            search: '/api/xray/bgpcommunity/searchItem',
            get:    '/api/xray/bgpcommunity/getItem/',
            set:    '/api/xray/bgpcommunity/setItem/',
            add:    '/api/xray/bgpcommunity/addItem',
            del:    '/api/xray/bgpcommunity/delItem/',
            toggle: '/api/xray/bgpcommunity/toggleItem/'
        });

        $('#DialogBgpPeer').on('shown.bs.modal', function () {
            $(this).find('.selectpicker').selectpicker('refresh');
        });

        var xrayFilterBlockSeq = 0;

        function xrayFilterDlg() {
            return $('#DialogBgpFilter');
        }

        function xrayFilterField($dlg, id) {
            return $dlg.find('[id="' + id + '"]');
        }

        function xrayFilterRow($el) {
            return $el.closest('tr');
        }

        function xrayFilterDefaultTun() {
            var fam = String(xrayFilterField(xrayFilterDlg(), 'filter.family').val() || 'ipv4');
            return fam === 'ipv6' ? 'ACTIVE_TUN6_IF' : 'ACTIVE_TUN4_IF';
        }

        function xrayFilterReadBlocks() {
            var $dlg = xrayFilterDlg();
            var blocks = [];
            $dlg.find('[data-xray-block-field="community"]').each(function () {
                var $comm = $(this);
                var $tun = $comm.closest('tr').nextAll('tr').find('[data-xray-block-field="tun_if"]').first();
                if (!$tun.length) {
                    $tun = $comm.closest('.xray-filter-block').find('[data-xray-block-field="tun_if"]');
                }
                blocks.push({
                    community: String($comm.val() || ''),
                    tun_if: String($tun.val() || '')
                });
            });
            return blocks;
        }

        function xrayFilterWriteBlocks() {
            var $dlg = xrayFilterDlg();
            var blocks = xrayFilterReadBlocks();
            if (!blocks.length) {
                blocks = [{
                    community: String(xrayFilterField($dlg, 'filter.community').val() || ''),
                    tun_if: String(xrayFilterField($dlg, 'filter.tun_if').val() || xrayFilterDefaultTun())
                }];
            }
            var $hid = xrayFilterField($dlg, 'filter.blocks');
            $hid.val(JSON.stringify(blocks));
            if (blocks[0]) {
                xrayFilterField($dlg, 'filter.community').val(blocks[0].community);
                xrayFilterField($dlg, 'filter.tun_if').val(blocks[0].tun_if);
            }
        }

        function xrayFilterParseStored() {
            var $dlg = xrayFilterDlg();
            var raw = String(xrayFilterField($dlg, 'filter.blocks').val() || '').trim();
            var blocks = [];
            if (raw !== '') {
                try {
                    var j = JSON.parse(raw);
                    if ($.isArray(j)) {
                        j.forEach(function (b) {
                            if (!b || typeof b !== 'object') {
                                return;
                            }
                            blocks.push({
                                community: String(b.community || ''),
                                tun_if: String(b.tun_if || '')
                            });
                        });
                    }
                } catch (e) {
                    blocks = [];
                }
            }
            if (!blocks.length) {
                blocks.push({
                    community: String(xrayFilterField($dlg, 'filter.community').val() || ''),
                    tun_if: String(xrayFilterField($dlg, 'filter.tun_if').val() || xrayFilterDefaultTun())
                });
            }
            return blocks;
        }

        function xrayFilterStripPicker($sel) {
            if ($sel.parent().hasClass('bootstrap-select')) {
                $sel.insertBefore($sel.parent());
                $sel.prevAll('.bootstrap-select').add($sel.nextAll('.bootstrap-select')).remove();
            }
            $sel.removeClass('selectpicker').show();
            return $sel;
        }

        function xrayFilterInitPicker($sel) {
            $sel.addClass('selectpicker');
            if ($.fn.selectpicker) {
                try {
                    $sel.selectpicker('destroy');
                } catch (e) { /* not initialized */ }
                $sel.selectpicker({size: 8});
            }
        }

        function xrayFilterExtraBlock(community, tunIf) {
            var $dlg = xrayFilterDlg();
            var n = ++xrayFilterBlockSeq;
            var $srcC = xrayFilterField($dlg, 'filter.community');
            var $srcT = xrayFilterField($dlg, 'filter.tun_if');
            var $rowC = xrayFilterRow($srcC).clone();
            var $rowT = xrayFilterRow($srcT).clone();
            $rowC.addClass('xray-filter-extra xray-filter-block-row xray-filter-block-start');
            $rowT.addClass('xray-filter-extra xray-filter-block-row xray-filter-block-end');
            $rowC.find('.hidden, .show').filter('[data-for]').remove();
            $rowT.find('.hidden, .show').filter('[data-for]').remove();
            $rowC.find('a.xray-showhelp').remove();
            $rowT.find('a.xray-showhelp').remove();
            $rowC.add($rowT).find('.bootstrap-select').each(function () {
                var $wrap = $(this);
                var $sel = $wrap.find('select').first();
                $sel.insertAfter($wrap);
                $wrap.remove();
            });

            var $newC = $rowC.find('select').first();
            var $newT = $rowT.find('select').first();
            xrayFilterStripPicker($newC);
            xrayFilterStripPicker($newT);
            $newC.attr('id', 'xray_filter_comm_' + n).attr('name', '').attr('data-xray-block-field', 'community');
            $newT.attr('id', 'xray_filter_tun_' + n).attr('name', '').attr('data-xray-block-field', 'tun_if');
            $newC.find('option').prop('selected', false);
            $newT.find('option').prop('selected', false);
            if (community) {
                $newC.val(community);
            }
            $newT.val(tunIf || xrayFilterDefaultTun());
            $dlg.find('.xray-filter-block-actions').before($rowC, $rowT);
            xrayFilterInitPicker($newC);
            xrayFilterInitPicker($newT);
        }

        function xrayFilterDialogSetup() {
            var $dlg = xrayFilterDlg();
            var $comm = xrayFilterField($dlg, 'filter.community');
            var $tun = xrayFilterField($dlg, 'filter.tun_if');
            var $fam = xrayFilterField($dlg, 'filter.family');
            var $blocks = xrayFilterField($dlg, 'filter.blocks');
            if (!$comm.length || !$tun.length) {
                return;
            }
            var $commRow = xrayFilterRow($comm);
            var $tunRow = xrayFilterRow($tun);
            var $famRow = xrayFilterRow($fam);
            var $blocksRow = xrayFilterRow($blocks);
            $blocksRow.hide();
            if ($famRow.length && $commRow.length) {
                $famRow.insertBefore($commRow);
            }
            $dlg.find('.xray-filter-extra').remove();
            if (!$dlg.find('.xray-filter-block-actions').length) {
                var $act = $('<tr class="xray-filter-block-actions"/>');
                $act.append($('<td colspan="3"/>').append(
                    $('<button type="button" class="btn btn-xs btn-primary xray-filter-block-add" title="{{ lang._("Add") }}"/>')
                        .append($('<span class="fa fa-fw fa-plus"/>')),
                    ' ',
                    $('<button type="button" class="btn btn-xs btn-default xray-filter-block-del" title="{{ lang._("Remove") }}"/>')
                        .append($('<span class="fa fa-fw fa-minus"/>'))
                ));
                $tunRow.after($act);
            }
            $comm.attr('data-xray-block-field', 'community');
            $tun.attr('data-xray-block-field', 'tun_if');
            $commRow.add($tunRow).addClass('xray-filter-block-row');
            $commRow.addClass('xray-filter-block-start');
            $tunRow.addClass('xray-filter-block-end');
            var stored = xrayFilterParseStored();
            $comm.val(stored[0].community);
            $tun.val(stored[0].tun_if || xrayFilterDefaultTun());
            for (var i = 1; i < stored.length; i++) {
                xrayFilterExtraBlock(stored[i].community, stored[i].tun_if);
            }
            $dlg.find('.selectpicker').selectpicker('refresh');
            xrayFilterWriteBlocks();
        }

        $('#DialogBgpFilter').on('shown.bs.modal', function () {
            xrayFilterDialogSetup();
        });
        $(document).on('click', '#DialogBgpFilter .xray-filter-block-add', function (e) {
            e.preventDefault();
            xrayFilterExtraBlock('', xrayFilterDefaultTun());
            xrayFilterWriteBlocks();
        });
        $(document).on('click', '#DialogBgpFilter .xray-filter-block-del', function (e) {
            e.preventDefault();
            var $dlg = xrayFilterDlg();
            var $extras = $dlg.find('tr.xray-filter-extra');
            if ($extras.length >= 2) {
                $extras.slice(-2).remove();
            }
            xrayFilterWriteBlocks();
        });
        $(document).on('change', '#DialogBgpFilter [data-xray-block-field]', function () {
            xrayFilterWriteBlocks();
        });
        $('#DialogBgpFilter').on('click', '.modal-footer .btn-primary', function () {
            xrayFilterWriteBlocks();
        });

        $(document).ajaxSuccess(function (e, xhr, settings) {
            var url = settings.url || '';
            if (/\/api\/xray\/bgppeer\/(addItem|setItem|delItem|toggleItem)\b/.test(url)) {
                openRoutingPeersStatus();
            }
        });

        var birdTestResultClearTimer = null;
        function clearBirdTestResultSoon($res, delayMs) {
            if (birdTestResultClearTimer) {
                clearTimeout(birdTestResultClearTimer);
                birdTestResultClearTimer = null;
            }
            birdTestResultClearTimer = setTimeout(function () {
                birdTestResultClearTimer = null;
                $res.text('').removeClass('text-success text-danger');
            }, delayMs || 30000);
        }
        function setBirdTestResult($res, ok, message) {
            if (birdTestResultClearTimer) {
                clearTimeout(birdTestResultClearTimer);
                birdTestResultClearTimer = null;
            }
            if (ok) {
                $res.removeClass('text-danger').addClass('text-success').text(message);
            } else {
                $res.removeClass('text-success').addClass('text-danger').text(message);
            }
            clearBirdTestResultSoon($res, 30000);
        }

        $(document).on('click', '.xray-btn-bird-testall', function () {
            var $res = $('.xray-bird-test-result');
            if (birdTestResultClearTimer) {
                clearTimeout(birdTestResultClearTimer);
                birdTestResultClearTimer = null;
            }
            if (!birdRunning) {
                setBirdTestResult($res, false, "{{ lang._('BIRD is not running.') }}");
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $res.removeClass('text-success text-danger').text("{{ lang._('Testing...') }}");
            ajaxGet("/api/xray/bgppeer/statusAll", {}, function (data) {
                $btn.prop('disabled', false);
                updateBirdToolbar();
                if (!applyPeerStatusPayload(data) || !birdRunning) {
                    setBirdTestResult($res, false, "{{ lang._('BIRD is not running.') }}");
                    return;
                }
                setBirdTestResult($res, true, "{{ lang._('Peer status updated.') }}");
            });
        });

        function isBgpRoutingTab() {
            return $('#routing-peers, #routing-filters, #routing-communities').filter('.active').length > 0;
        }

        function isStaticTab() {
            return $('#routing-static').filter('.active').length > 0;
        }

        var staticProxyUrlsCache = [];
        var staticDirectUrlsCache = [];
        var staticBlockUrlsCache = [];
        var staticGeodatUrlsCache = [];
        var staticGeodatActiveUrl = '';

        function staticUrlEnabledOn(enabled, hasUrl) {
            // Missing flag: legacy / new empty row — checked only when URL present and server omitted flag
            if (enabled === undefined || enabled === null || enabled === '') {
                return !!hasUrl;
            }
            if (enabled === true || enabled === 1 || enabled === '1' || enabled === 'true' || enabled === 'on' || enabled === 'yes') {
                return true;
            }
            return false;
        }

        function staticUrlRow(key, value, enabled) {
            var val = value == null ? '' : String(value);
            var on = staticUrlEnabledOn(enabled, val.trim() !== '');
            var $tr = $('<tr/>');
            var $inp = $('<input type="text" class="form-control"/>')
                .attr('data-key', key)
                .attr('placeholder', 'https://')
                .val(val);
            var $fetch = $('<button type="button" class="btn btn-xs btn-default static-url-fetch"/>')
                .attr('title', '{{ lang._("Download and save this URL") }}')
                .text('✔');
            var $en = $('<label class="static-url-enable"/>')
                .append($('<input type="checkbox" class="static-url-enabled"/>').prop('checked', on))
                .append(document.createTextNode(' {{ lang._("Enable") }}'));
            $tr.append($('<td/>').append(
                $('<div class="static-row-edit"/>').append($inp, $fetch, $en)
            ));
            return $tr;
        }

        function staticListKind($tbody) {
            var id = String(($tbody && $tbody.attr('id')) || '');
            if (id.indexOf('Proxy') >= 0) {
                return 'proxy';
            }
            if (id.indexOf('Direct') >= 0) {
                return 'direct';
            }
            if (id.indexOf('Block') >= 0) {
                return 'block';
            }
            if (id.indexOf('Geodat') >= 0) {
                return 'geodat';
            }
            return 'proxy';
        }

        function staticCollectKv($tbody) {
            var rows = [];
            $tbody.find('tr').each(function () {
                var $inp = $(this).find('input[type="text"]').first();
                if (!$inp.length) {
                    return;
                }
                rows.push({
                    key: String($inp.attr('data-key') || 'url'),
                    value: String($inp.val() || ''),
                    enabled: $(this).find('.static-url-enabled').is(':checked') ? '1' : '0'
                });
            });
            return rows;
        }

        function staticFillOrEmpty($tbody, rows, key) {
            $tbody.empty();
            (rows || []).forEach(function (row) {
                var val = row.value || row.url || '';
                $tbody.append(staticUrlRow(row.key || key, val, row.enabled));
            });
            if (!$tbody.find('tr').length) {
                $tbody.append(staticUrlRow(key, '', false));
            }
        }

        function staticEnsureUrlLists() {
            ['#staticProxyUrlsKv', '#staticDirectUrlsKv', '#staticBlockUrlsKv', '#staticGeodatUrlsKv'].forEach(function (sel) {
                var $tbody = $(sel);
                if ($tbody.length && !$tbody.find('tr').length) {
                    $tbody.append(staticUrlRow(String($tbody.attr('data-row-key') || 'url'), '', false));
                }
            });
        }

        function staticWritePayload() {
            var proxyUrls = staticCollectKv($('#staticProxyUrlsKv'));
            if (!$('#staticProxyUrlsKv input').length && staticProxyUrlsCache.length) {
                proxyUrls = staticProxyUrlsCache;
            }
            var directUrls = staticCollectKv($('#staticDirectUrlsKv'));
            if (!$('#staticDirectUrlsKv input').length && staticDirectUrlsCache.length) {
                directUrls = staticDirectUrlsCache;
            }
            var blockUrls = staticCollectKv($('#staticBlockUrlsKv'));
            if (!$('#staticBlockUrlsKv input').length && staticBlockUrlsCache.length) {
                blockUrls = staticBlockUrlsCache;
            }
            var geodatUrls = staticCollectKv($('#staticGeodatUrlsKv'));
            if (!$('#staticGeodatUrlsKv input').length && staticGeodatUrlsCache.length) {
                geodatUrls = staticGeodatUrlsCache;
            }
            return {
                enabled: $('#staticEnabled').is(':checked') ? '1' : '0',
                proxy_urls: JSON.stringify(proxyUrls),
                direct_urls: JSON.stringify(directUrls),
                block_urls: JSON.stringify(blockUrls),
                geodat_urls: JSON.stringify(geodatUrls)
            };
        }

        function staticSetCounts(counts) {
            counts = counts || {};
            var tip = '{{ lang._("Prefixes in the summarized apply.inc (IPv4 / IPv6)") }}';
            function setLabel(id, n4, n6) {
                var $el = $(id);
                if (!$el.length) {
                    return;
                }
                n4 = parseInt(n4, 10) || 0;
                n6 = parseInt(n6, 10) || 0;
                if (n4 + n6 > 0) {
                    $el.text('v4:' + n4 + ' v6:' + n6).attr('title', tip).show();
                } else {
                    $el.hide().text('').removeAttr('title');
                }
            }
            setLabel('#staticProxyCount', counts.proxy4, counts.proxy6);
            setLabel('#staticDirectCount', counts.direct4, counts.direct6);
            setLabel('#staticBlockCount', counts.block4, counts.block6);
        }

        function loadStaticConf() {
            ajaxGet('/api/xray/service/staticconf', {}, function (data) {
                if (!data || data.result === 'failed') {
                    staticEnsureUrlLists();
                    return;
                }
                $('#staticEnabled').prop('checked', String(data.enabled || '0') === '1');
                staticFillOrEmpty($('#staticProxyUrlsKv'), data.proxy_urls, 'url');
                staticFillOrEmpty($('#staticDirectUrlsKv'), data.direct_urls, 'url');
                staticFillOrEmpty($('#staticBlockUrlsKv'), data.block_urls, 'url');
                staticFillOrEmpty($('#staticGeodatUrlsKv'), data.geodat_urls, 'url');
                staticProxyUrlsCache = staticCollectKv($('#staticProxyUrlsKv'));
                staticDirectUrlsCache = staticCollectKv($('#staticDirectUrlsKv'));
                staticBlockUrlsCache = staticCollectKv($('#staticBlockUrlsKv'));
                staticGeodatUrlsCache = staticCollectKv($('#staticGeodatUrlsKv'));
                staticSetCounts(data.counts);
                staticApplyExtra(data);
            });
        }

        function staticCollectGeodatUrls() {
            return staticCollectKv($('#staticGeodatUrlsKv')).filter(function (r) {
                return String(r.value || '').trim() !== '';
            });
        }

        function xrayNormalizeApiData(data) {
            if (data == null) {
                return null;
            }
            if (typeof data === 'string') {
                var s = data.trim();
                try {
                    data = JSON.parse(s);
                } catch (e1) {
                    var m = s.match(/\{[\s\S]*\}/);
                    if (!m) {
                        return null;
                    }
                    try {
                        data = JSON.parse(m[0]);
                    } catch (e2) {
                        return null;
                    }
                }
            }
            if (typeof data !== 'object') {
                return null;
            }
            // Failed API sometimes puts the whole JSON blob into message — recover categories
            if ((!data.categories || !data.categories.length) && typeof data.message === 'string'
                    && data.message.indexOf('{') >= 0 && data.message.indexOf('categories') >= 0) {
                var recovered = xrayNormalizeApiData(data.message);
                if (recovered && recovered.categories) {
                    return recovered;
                }
            }
            if (typeof data.categories === 'string') {
                try {
                    data.categories = JSON.parse(data.categories);
                } catch (e3) {
                    data.categories = [];
                }
            }
            if (!Array.isArray(data.categories)) {
                data.categories = [];
            }
            return data;
        }

        var staticGeodatXhr = null;
        var staticGeodatPickSilent = false;

        function staticFillGeodatUrlPick(selected) {
            staticGeodatPickSilent = true;
            var $pick = $('#staticGeodatUrlPick').empty();
            staticCollectGeodatUrls().forEach(function (row) {
                var u = String(row.value || '').trim();
                if (u) {
                    $pick.append($('<option/>').attr('value', u).text(u));
                }
            });
            if (!$pick.find('option').length) {
                $pick.append($('<option/>').attr('value', '').text('—'));
            }
            if (selected) {
                $pick.val(selected);
            }
            staticGeodatPickSilent = false;
        }

        function staticGeodatFillModal(categories) {
            var $tb = $('#staticGeodatCats').empty();
            if (!Array.isArray(categories)) {
                categories = [];
            }
            categories.forEach(function (cat) {
                if (typeof cat === 'string') {
                    try { cat = JSON.parse(cat); } catch (e) { return; }
                }
                if (!cat || typeof cat !== 'object') {
                    return;
                }
                var name = String(cat.name || cat.key || '');
                if (!name) {
                    return;
                }
                var action = String(cat.action || cat.value || '');
                var $sel = $('<select class="form-control input-sm static-geodat-action"/>')
                    .attr('data-cat', name);
                [
                    {v: '', t: '—'},
                    {v: 'proxy', t: '{{ lang._("PROXY") }}'},
                    {v: 'direct', t: '{{ lang._("DIRECT") }}'},
                    {v: 'block', t: '{{ lang._("BLOCK") }}'}
                ].forEach(function (opt) {
                    $sel.append($('<option/>').attr('value', opt.v).text(opt.t));
                });
                $sel.val(action === 'proxy' || action === 'direct' || action === 'block' ? action : '');
                var $tr = $('<tr/>');
                $tr.append($('<td/>').text(name));
                $tr.append($('<td/>').text(String(cat.count != null ? cat.count : '')));
                $tr.append($('<td/>').append($sel));
                $tb.append($tr);
            });
            staticGeodatApplyFilter();
        }

        function staticGeodatLoadCategories(url) {
            url = String(url || '').trim();
            if (!url) {
                return;
            }
            staticGeodatActiveUrl = url;
            $('#staticGeodatModalMsg').text("{{ lang._('Loading categories…') }}");
            $('#staticGeodatCats').empty();
            if (staticGeodatXhr && staticGeodatXhr.readyState !== 4) {
                staticGeodatXhr.abort();
            }
            staticGeodatXhr = $.ajax({
                url: '/api/xray/service/staticgeodat',
                type: 'POST',
                dataType: 'json',
                timeout: 180000,
                data: {
                    geodat_urls: JSON.stringify(staticCollectGeodatUrls()),
                    geodat_url: url
                },
                success: function (data) {
                    data = xrayNormalizeApiData(data);
                    if (!data || data.result === 'failed') {
                        var failMsg = (data && data.message) ? String(data.message) : 'failed';
                        // Never dump a raw JSON blob into the status line
                        if (failMsg.charAt(0) === '{' || failMsg.length > 300) {
                            failMsg = "{{ lang._('Failed to load categories.') }}";
                        }
                        $('#staticGeodatModalMsg').text(failMsg);
                        return;
                    }
                    $('#staticGeodatModalMsg').text(data.message || "{{ lang._('Categories loaded.') }}");
                    staticGeodatFillModal(data.categories || []);
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    $('#staticGeodatModalMsg').text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        }

        function staticGeodatApplyFilter() {
            var q = String($('#staticGeodatFilter').val() || '').toLowerCase();
            $('#staticGeodatCats tr').each(function () {
                var name = $(this).children('td').first().text().toLowerCase();
                $(this).toggle(!q || name.indexOf(q) !== -1);
            });
        }

        function staticGeodatCollectCats() {
            var rows = [];
            $('#staticGeodatCats select').each(function () {
                var name = String($(this).attr('data-cat') || '');
                var action = String($(this).val() || '');
                if (name && (action === 'proxy' || action === 'direct' || action === 'block')) {
                    rows.push({key: name, value: action});
                }
            });
            return rows;
        }

        function isDnstapTab() {
            return $('#dnstap').filter('.active').length > 0;
        }

        function isDomainsTab() {
            return $('#domains').filter('.active').length > 0;
        }

        var dnstapExtraProxy = [];
        var dnstapExtraDirect = [];
        var dnstapExtraBlock = [];
        var dnstapExtrasDirty = false;
        var dnstapConfCache = [];
        var dnstapProxyUrlsCache = [];
        var dnstapDirectUrlsCache = [];
        var dnstapBlockUrlsCache = [];
        var dnstapGeodatUrlsCache = [];
        var dnstapGeodatActiveUrl = '';
        var domainsListKind = 'proxy';
        var domainsListMode = 'domain';
        var staticExtraProxy = [];
        var staticExtraDirect = [];
        var staticExtraBlock = [];
        var staticExtraDirty = false;
        var staticExtraBusy = false;
        var staticExtraSeq = 0;

        function dnstapNormalizeDomain(raw) {
            var v = String(raw || '').replace(/^\uFEFF/, '').split('#')[0].trim().toLowerCase();
            v = v.replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/\.+$/, '');
            if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/.test(v)) {
                return '';
            }
            return v;
        }

        function dnstapRowsToDomains(rows) {
            var out = [];
            var seen = {};
            (rows || []).forEach(function (row) {
                var d = dnstapNormalizeDomain(row && row.value != null ? row.value : row);
                if (d && !seen[d]) {
                    seen[d] = true;
                    out.push(d);
                }
            });
            return out;
        }

        function dnstapDomainsToRows(list) {
            return (list || []).map(function (d) {
                return { key: 'domain', value: d };
            });
        }

        function dnstapWritePayload() {
            var conf = dnstapCollectKv($('#dnstapConfKv'));
            if (!conf.length && dnstapConfCache.length) {
                conf = dnstapConfCache;
            }
            var proxyUrls = dnstapCollectUrlKv($('#dnstapProxyUrlsKv'));
            if (!$('#dnstapProxyUrlsKv input[type="text"]').length && dnstapProxyUrlsCache.length) {
                proxyUrls = dnstapProxyUrlsCache;
            }
            var directUrls = dnstapCollectUrlKv($('#dnstapDirectUrlsKv'));
            if (!$('#dnstapDirectUrlsKv input[type="text"]').length && dnstapDirectUrlsCache.length) {
                directUrls = dnstapDirectUrlsCache;
            }
            var blockUrls = dnstapCollectUrlKv($('#dnstapBlockUrlsKv'));
            if (!$('#dnstapBlockUrlsKv input[type="text"]').length && dnstapBlockUrlsCache.length) {
                blockUrls = dnstapBlockUrlsCache;
            }
            var geodatUrls = dnstapCollectUrlKv($('#dnstapGeodatUrlsKv'));
            if (!$('#dnstapGeodatUrlsKv input[type="text"]').length && dnstapGeodatUrlsCache.length) {
                geodatUrls = dnstapGeodatUrlsCache;
            }
            return {
                conf: JSON.stringify(conf),
                proxy: JSON.stringify(dnstapDomainsToRows(dnstapExtraProxy)),
                proxy_urls: JSON.stringify(proxyUrls),
                direct: JSON.stringify(dnstapDomainsToRows(dnstapExtraDirect)),
                direct_urls: JSON.stringify(directUrls),
                block: JSON.stringify(dnstapDomainsToRows(dnstapExtraBlock)),
                block_urls: JSON.stringify(blockUrls),
                geodat_urls: JSON.stringify(geodatUrls)
            };
        }

        function dnstapCollectGeodatUrls() {
            var urls = dnstapCollectUrlKv($('#dnstapGeodatUrlsKv'));
            if (!$('#dnstapGeodatUrlsKv input[type="text"]').length && dnstapGeodatUrlsCache.length) {
                urls = dnstapGeodatUrlsCache;
            }
            return urls.filter(function (row) {
                return /^https?:\/\//i.test(String(row.value || '').trim());
            });
        }

        function updateDnstapBadge(running) {
            $('.xray-badge-dnstap')
                .removeClass('label-success label-danger label-default')
                .addClass(running ? 'label-success' : 'label-danger')
                .text('dnstap_bgp: ' + (running ? 'running' : 'stopped'));
        }

        function dnstapConfLabel(key) {
            var map = {
                ttl: '{{ lang._("Routing prefix TTL") }}',
                ipv6: '{{ lang._("Enable IPv6") }}',
                'bgp.peers': '{{ lang._("Bird IP") }}',
                'bgp.sourceIP': '{{ lang._("DNStap IP") }}',
                'bgp.proxy_communities': '{{ lang._("PROXY communities") }}',
                'bgp.direct_communities': '{{ lang._("DIRECT communities") }}',
                'bgp.block_communities': '{{ lang._("BLOCK communities") }}'
            };
            return map[key] || key;
        }

        function dnstapConfHelp(key) {
            var map = {
                ttl: '{{ lang._("How long advertised BGP prefixes stay in the dnstap-bgp cache (Go duration, e.g. 24h).") }}',
                ipv6: '{{ lang._("Enable IPv6 AFI in dnstap-bgp and the ipv6 channel on the BIRD dnstap peer.") }}',
                'bgp.peers': '{{ lang._("BIRD neighbor address (host side of the dnstap iBGP session).") }}',
                'bgp.sourceIP': '{{ lang._("dnstap-bgp source address (jail / DNStap side of the iBGP session).") }}',
                'bgp.proxy_communities': '{{ lang._("Fixed BGP community on PROXY prefixes (local AS:777). Not editable.") }}',
                'bgp.direct_communities': '{{ lang._("Fixed BGP community on DIRECT prefixes (local AS:555). Not editable.") }}',
                'bgp.block_communities': '{{ lang._("Fixed BGP community on BLOCK prefixes (local AS:888). Not editable.") }}'
            };
            return map[key] || '';
        }

        function dnstapHelpId(key) {
            return 'help_for_dnstap_' + String(key).replace(/[^A-Za-z0-9_]/g, '_');
        }

        function dnstapHelpBlock(hid, text) {
            if (!text) {
                return $();
            }
            return $('<div/>').addClass(xrayHelpClasses()).attr('data-for', hid)
                .append($('<small/>').text(text));
        }

        function dnstapConfRow(key, value) {
            var hid = dnstapHelpId(key);
            var help = dnstapConfHelp(key);
            var $tr = $('<tr/>');
            var $lab = $('<td class="dnstap-k"/>');
            if (help) {
                $lab.append($('<a href="#" class="xray-showhelp"/>').attr('id', hid)
                    .append($('<i class="fa fa-info-circle"/>')));
                $lab.append(document.createTextNode(' '));
            }
            $lab.append(document.createTextNode(dnstapConfLabel(key)));
            $tr.append($lab);
            var $val = $('<td/>');
            if (key === 'ipv6') {
                var on = value === true || value === 1 || String(value).toLowerCase() === 'true'
                    || String(value) === '1';
                var $cb = $('<input type="checkbox"/>')
                    .attr('data-key', key)
                    .prop('checked', on);
                $val.append($cb);
            } else {
                var $inp = $('<input type="text" class="form-control"/>')
                    .attr('data-key', key)
                    .val(value == null ? '' : String(value));
                if (key === 'bgp.proxy_communities' || key === 'bgp.direct_communities' || key === 'bgp.block_communities') {
                    $inp.prop('readonly', true).attr('tabindex', '-1')
                        .css({'cursor': 'default'});
                }
                $val.append($inp);
            }
            $val.append(dnstapHelpBlock(hid, help));
            $tr.append($val);
            return $tr;
        }

        function dnstapKvRow(key, value) {
            var $tr = $('<tr/>');
            var $inp = $('<input type="text" class="form-control"/>')
                .attr('data-key', key)
                .val(value == null ? '' : String(value));
            $tr.append($('<td/>').append($('<div class="dnstap-row-edit"/>').append($inp)));
            return $tr;
        }

        function dnstapUrlEnabledOn(enabled, hasUrl) {
            if (enabled === undefined || enabled === null || enabled === '') {
                return !!hasUrl;
            }
            if (enabled === true || enabled === 1 || enabled === '1' || enabled === 'true' || enabled === 'on' || enabled === 'yes') {
                return true;
            }
            return false;
        }

        function dnstapUrlRow(key, value, enabled) {
            var val = value == null ? '' : String(value);
            var on = dnstapUrlEnabledOn(enabled, val.trim() !== '');
            var $tr = $('<tr/>');
            var $inp = $('<input type="text" class="form-control"/>')
                .attr('data-key', key)
                .attr('placeholder', 'https://')
                .val(val);
            var $fetch = $('<button type="button" class="btn btn-xs btn-default dnstap-url-fetch"/>')
                .attr('title', '{{ lang._("Download and save this URL") }}')
                .text('✔');
            var $en = $('<label class="dnstap-url-enable"/>')
                .append($('<input type="checkbox" class="dnstap-url-enabled"/>').prop('checked', on))
                .append(document.createTextNode(' {{ lang._("Enable") }}'));
            $tr.append($('<td/>').append(
                $('<div class="dnstap-row-edit"/>').append($inp, $fetch, $en)
            ));
            return $tr;
        }

        function dnstapListKind($tbody) {
            var id = String(($tbody && $tbody.attr('id')) || '');
            if (id.indexOf('Proxy') >= 0) {
                return 'proxy';
            }
            if (id.indexOf('Direct') >= 0) {
                return 'direct';
            }
            if (id.indexOf('Block') >= 0) {
                return 'block';
            }
            if (id.indexOf('Geodat') >= 0) {
                return 'geodat';
            }
            return 'proxy';
        }

        function dnstapFillKv($tbody, rows) {
            $tbody.empty();
            (rows || []).forEach(function (row) {
                $tbody.append(dnstapKvRow(row.key || '', row.value || ''));
            });
        }

        function dnstapCollectKv($tbody) {
            var rows = [];
            $tbody.find('input').each(function () {
                var $el = $(this);
                rows.push({
                    key: String($el.attr('data-key') || ''),
                    value: $el.is(':checkbox') ? ($el.prop('checked') ? 'true' : 'false') : ($el.val() || '')
                });
            });
            return rows;
        }

        function dnstapCollectUrlKv($tbody) {
            var rows = [];
            $tbody.find('tr').each(function () {
                var $inp = $(this).find('input[type="text"]').first();
                if (!$inp.length) {
                    return;
                }
                rows.push({
                    key: String($inp.attr('data-key') || 'url'),
                    value: String($inp.val() || ''),
                    enabled: $(this).find('.dnstap-url-enabled').is(':checked') ? '1' : '0'
                });
            });
            return rows;
        }

        function dnstapFillOrEmpty($tbody, rows, key) {
            $tbody.empty();
            (rows || []).forEach(function (row) {
                var val = row.value || row.url || '';
                $tbody.append(dnstapUrlRow(row.key || key, val, row.enabled));
            });
            if (!$tbody.find('tr').length) {
                $tbody.append(dnstapUrlRow(key, '', false));
            }
        }

        function dnstapSetCount($el, n) {
            n = parseInt(n, 10);
            if (n > 0) {
                $el.text(String(n)).attr('title', '{{ lang._("Unique domains in the summarized file") }}').show();
            } else {
                $el.text('').hide();
            }
        }

        function loadDnstapConf() {
            ajaxGet('/api/xray/service/dnstapconf', {}, function (data) {
                if (!data || data.result === 'failed') {
                    updateDnstapBadge(false);
                    return;
                }
                updateDnstapBadge(!!data.running);
                dnstapSetCount($('#dnstapProxyCount'), data.proxy_count || data.blocked_count || data.domains_count);
                dnstapSetCount($('#dnstapDirectCount'), data.direct_count || data.unblocked_count);
                dnstapSetCount($('#dnstapBlockCount'), data.block_count);
                (function () {
                    var $tbody = $('#dnstapConfKv');
                    $tbody.empty();
                    (data.conf || []).forEach(function (row) {
                        $tbody.append(dnstapConfRow(row.key || '', row.value));
                    });
                })();
                dnstapFillOrEmpty($('#dnstapProxyUrlsKv'), data.proxy_urls || data.blocked_urls || data.urls, 'url');
                dnstapFillOrEmpty($('#dnstapDirectUrlsKv'), data.direct_urls || data.unblocked_urls, 'url');
                dnstapFillOrEmpty($('#dnstapBlockUrlsKv'), data.block_urls, 'url');
                dnstapFillOrEmpty($('#dnstapGeodatUrlsKv'), data.geodat_urls, 'url');
                dnstapConfCache = dnstapCollectKv($('#dnstapConfKv'));
                dnstapProxyUrlsCache = dnstapCollectUrlKv($('#dnstapProxyUrlsKv'));
                dnstapDirectUrlsCache = dnstapCollectUrlKv($('#dnstapDirectUrlsKv'));
                dnstapBlockUrlsCache = dnstapCollectUrlKv($('#dnstapBlockUrlsKv'));
                dnstapGeodatUrlsCache = dnstapCollectUrlKv($('#dnstapGeodatUrlsKv'));
                if (!dnstapExtrasDirty) {
                    dnstapExtraProxy = dnstapRowsToDomains(data.proxy || data.blocked_extra || data.blocked || data.domains);
                    dnstapExtraDirect = dnstapRowsToDomains(data.direct || data.unblocked_extra || data.unblocked);
                    dnstapExtraBlock = dnstapRowsToDomains(data.block);
                }
                domainsExtraRefreshCounts();
            });
        }

        function setApplyEndpoint() {
            var endpoint = '/api/xray/service/reconfigure';
            if (isBgpRoutingTab()) {
                endpoint = '/api/xray/service/bgpwrite';
            } else if (isStaticTab()) {
                endpoint = '/api/xray/service/staticwrite';
            } else if (isDnstapTab() || isDomainsTab()) {
                endpoint = '/api/xray/service/dnstapwrite';
            }
            $('#reconfigureAct').data('endpoint', endpoint).attr('data-endpoint', endpoint);
        }

        $('a[data-toggle="tab"]').on('shown.bs.tab', function () {
            setApplyEndpoint();
        });

        $('a[data-toggle="tab"][href^="#routing-"]').on('shown.bs.tab', function (e) {
            $(e.target).closest('li.dropdown').addClass('active');
            var href = $(e.target).attr('href');
            var $grid = $(href).find('table[id^="grid-"]');
            if ($grid.length) {
                $grid.bootgrid('reload');
            }
            if (href.indexOf('#routing-') === 0) {
                openRoutingPeersStatus();
            }
        });

        $('a[data-toggle="tab"][href="#dnstap"]').on('shown.bs.tab', function () {
            loadDnstapConf();
        });
        $('a[data-toggle="tab"][href="#domains"]').on('shown.bs.tab', function () {
            loadDnstapConf();
            loadStaticExtra();
        });
        $('a[data-toggle="tab"][href="#routing-static"]').on('shown.bs.tab', function () {
            loadStaticConf();
        });
        $(document).on('input', '#staticGeodatFilter', staticGeodatApplyFilter);
        $(document).on('change', '#staticGeodatUrlPick', function () {
            if (staticGeodatPickSilent) {
                return;
            }
            staticGeodatLoadCategories(String($(this).val() || ''));
        });

        $(document).on('click', '.dnstap-url-add', function () {
            var $tbody = $('#' + $(this).attr('data-list'));
            if (!$tbody.length) {
                return;
            }
            var key = String($tbody.attr('data-row-key') || 'url');
            $tbody.append(dnstapUrlRow(key, '', false));
        });
        $(document).on('click', '.dnstap-url-remove', function () {
            var $tbody = $('#' + $(this).attr('data-list'));
            if (!$tbody.length) {
                return;
            }
            if ($tbody.find('tr').length <= 1) {
                $tbody.find('input[type="text"]').val('');
                $tbody.find('.dnstap-url-enabled').prop('checked', false);
                return;
            }
            $tbody.find('tr').last().remove();
        });
        $(document).on('click', '.dnstap-url-fetch', function () {
            var $tr = $(this).closest('tr');
            var $tbody = $tr.closest('tbody');
            var url = String($tr.find('input[type="text"]').val() || '').trim();
            var kind = dnstapListKind($tbody);
            var enabled = $tr.find('.dnstap-url-enabled').is(':checked') ? '1' : '0';
            if (!url || !/^https?:\/\//i.test(url)) {
                BootstrapDialog.show({
                    type: BootstrapDialog.TYPE_WARNING,
                    title: "{{ lang._('DNStap') }}",
                    message: "{{ lang._('Enter a valid HTTP(S) URL first.') }}"
                });
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '/api/xray/service/dnstapfetchone',
                type: 'POST',
                dataType: 'json',
                timeout: 180000,
                data: {kind: kind, url: url, enabled: enabled},
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (!data || data.result === 'failed') {
                        alert(data && data.message ? data.message : '{{ lang._("Download failed") }}');
                        return;
                    }
                    loadDnstapConf();
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        });
        $(document).on('click', '.static-url-add', function () {
            var $tbody = $('#' + $(this).attr('data-list'));
            if (!$tbody.length) {
                return;
            }
            var key = String($tbody.attr('data-row-key') || 'url');
            $tbody.append(staticUrlRow(key, '', false));
        });
        $(document).on('click', '.static-url-remove', function () {
            var $tbody = $('#' + $(this).attr('data-list'));
            if (!$tbody.length) {
                return;
            }
            if ($tbody.find('tr').length <= 1) {
                $tbody.find('input[type="text"]').val('');
                $tbody.find('.static-url-enabled').prop('checked', false);
                return;
            }
            $tbody.find('tr').last().remove();
        });
        $(document).on('click', '.static-url-fetch', function () {
            var $tr = $(this).closest('tr');
            var $tbody = $tr.closest('tbody');
            var url = String($tr.find('input[type="text"]').val() || '').trim();
            var kind = staticListKind($tbody);
            var enabled = $tr.find('.static-url-enabled').is(':checked') ? '1' : '0';
            if (!url || !/^https?:\/\//i.test(url)) {
                BootstrapDialog.show({
                    type: BootstrapDialog.TYPE_WARNING,
                    title: "{{ lang._('Static') }}",
                    message: "{{ lang._('Enter a valid HTTP(S) URL first.') }}"
                });
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '/api/xray/service/staticfetchone',
                type: 'POST',
                dataType: 'json',
                timeout: 180000,
                data: {kind: kind, url: url, enabled: enabled},
                success: function (data) {
                    $btn.prop('disabled', false);
                    // Always reload so persisted URL stays visible in the list
                    loadStaticConf();
                    if (!data || data.result === 'failed') {
                        alert(data && data.message ? data.message : '{{ lang._("Download failed") }}');
                        return;
                    }
                    if (data.download_ok === false) {
                        alert(data.message || '{{ lang._("URL saved, but download failed") }}');
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    loadStaticConf();
                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        });
        $('#staticGeodatCategory').on('click', function () {
            var urls = staticCollectGeodatUrls();
            if (!urls.length) {
                BootstrapDialog.show({
                    type: BootstrapDialog.TYPE_WARNING,
                    title: "{{ lang._('Geodat') }}",
                    message: "{{ lang._('Add at least one Geodat URL first.') }}"
                });
                return;
            }
            staticFillGeodatUrlPick(staticGeodatActiveUrl || urls[0].value);
            $('#staticGeodatCats').empty();
            $('#staticGeodatModalMsg').text("{{ lang._('Loading categories…') }}");
            $('#staticGeodatModal').modal('show');
            // Direct load — avoid double-fire from select rebuild + change
            staticGeodatLoadCategories(String($('#staticGeodatUrlPick').val() || ''));
        });
        $('#staticGeodatModal').on('click', '.static-geodat-save', function () {
            var url = String($('#staticGeodatUrlPick').val() || '');
            if (!url) {
                return;
            }
            var cats = staticGeodatCollectCats();
            var $saveBtn = $(this).prop('disabled', true);
            $('#staticGeodatModalMsg').text("{{ lang._('Saving…') }}");
            $.ajax({
                url: '/api/xray/service/staticgeodat',
                type: 'POST',
                dataType: 'json',
                timeout: 180000,
                data: {
                    geodat_urls: JSON.stringify(staticCollectGeodatUrls()),
                    geodat_url: url,
                    geodat_categories: JSON.stringify(cats)
                },
                success: function (data) {
                    $saveBtn.prop('disabled', false);
                    data = xrayNormalizeApiData(data);
                    if (!data || data.result === 'failed') {
                        var failMsg = (data && data.message) ? String(data.message) : 'failed';
                        if (failMsg.charAt(0) === '{' || failMsg.length > 300) {
                            failMsg = "{{ lang._('Failed to save categories.') }}";
                        }
                        $('#staticGeodatModalMsg').text(failMsg);
                        return;
                    }
                    staticGeodatActiveUrl = url;
                    if (data.categories && data.categories.length) {
                        staticGeodatFillModal(data.categories);
                    }
                    $('#staticGeodatModal').modal('hide');
                },
                error: function (xhr) {
                    $saveBtn.prop('disabled', false);
                    $('#staticGeodatModalMsg').text("{{ lang._('HTTP error:') }} " + xhr.status
                        + "{{ lang._(' — assignments may still have been written; reopen Category to verify.') }}");
                }
            });
        });
        function dnstapGeodatFillModal(categories) {
            var $tb = $('#dnstapGeodatCats').empty();
            if (!Array.isArray(categories)) {
                categories = [];
            }
            categories.forEach(function (cat) {
                if (typeof cat === 'string') {
                    try { cat = JSON.parse(cat); } catch (e) { return; }
                }
                if (!cat || typeof cat !== 'object') {
                    return;
                }
                var name = String(cat.name || cat.key || '');
                if (!name) {
                    return;
                }
                var action = String(cat.action || cat.value || '');
                var $sel = $('<select class="form-control input-sm dnstap-geodat-action"/>')
                    .attr('data-cat', name);
                [
                    {v: '', t: '—'},
                    {v: 'proxy', t: '{{ lang._("PROXY") }}'},
                    {v: 'direct', t: '{{ lang._("DIRECT") }}'},
                    {v: 'block', t: '{{ lang._("BLOCK") }}'}
                ].forEach(function (opt) {
                    $sel.append($('<option/>').attr('value', opt.v).text(opt.t));
                });
                if (action === 'blocked') {
                    action = 'proxy';
                } else if (action === 'unblocked') {
                    action = 'direct';
                }
                $sel.val(action === 'proxy' || action === 'direct' || action === 'block' ? action : '');
                var $tr = $('<tr/>');
                $tr.append($('<td/>').text(name));
                $tr.append($('<td/>').text(String(cat.count != null ? cat.count : '')));
                $tr.append($('<td/>').append($sel));
                $tb.append($tr);
            });
            dnstapGeodatApplyFilter();
        }

        function dnstapGeodatApplyFilter() {
            var q = String($('#dnstapGeodatFilter').val() || '').toLowerCase();
            $('#dnstapGeodatCats tr').each(function () {
                var name = $(this).children('td').first().text().toLowerCase();
                $(this).toggle(!q || name.indexOf(q) !== -1);
            });
        }

        function dnstapGeodatCollectCats() {
            var rows = [];
            $('#dnstapGeodatCats select').each(function () {
                var name = String($(this).attr('data-cat') || '');
                var action = String($(this).val() || '');
                if (name && (action === 'proxy' || action === 'direct' || action === 'block')) {
                    rows.push({key: name, value: action});
                }
            });
            return rows;
        }

        $(document).on('input', '#dnstapGeodatFilter', dnstapGeodatApplyFilter);

        var dnstapGeodatXhr = null;
        var dnstapGeodatPickSilent = false;

        function dnstapGeodatFillUrlPick(selected) {
            dnstapGeodatPickSilent = true;
            var $pick = $('#dnstapGeodatUrlPick').empty();
            dnstapCollectGeodatUrls().forEach(function (row) {
                var u = String(row.value || '').trim();
                $pick.append($('<option/>').attr('value', u).text(u));
            });
            if (selected) {
                $pick.val(selected);
            }
            dnstapGeodatActiveUrl = String($pick.val() || '');
            dnstapGeodatPickSilent = false;
        }

        function dnstapGeodatLoad(url) {
            var urls = dnstapCollectGeodatUrls();
            if (!url) {
                return;
            }
            dnstapGeodatActiveUrl = url;
            $('#dnstapGeodatStatus').text('{{ lang._("Downloading geosite.dat…") }}');
            $('#dnstapGeodatCats').empty();
            if (dnstapGeodatXhr && dnstapGeodatXhr.readyState !== 4) {
                dnstapGeodatXhr.abort();
            }
            dnstapGeodatXhr = $.ajax({
                url: '/api/xray/service/dnstapgeodat',
                type: 'POST',
                dataType: 'json',
                timeout: 180000,
                data: {
                    geodat_urls: JSON.stringify(urls),
                    geodat_url: url
                },
                success: function (data) {
                    data = xrayNormalizeApiData(data);
                    if (!data || data.result === 'failed') {
                        var failMsg = data && data.message
                            ? String(data.message)
                            : '{{ lang._("Failed to parse geosite.dat") }}';
                        if (failMsg.charAt(0) === '{' || failMsg.length > 300) {
                            failMsg = '{{ lang._("Failed to parse geosite.dat") }}';
                        }
                        $('#dnstapGeodatStatus').text(failMsg);
                        return;
                    }
                    var n = (data.categories || []).length;
                    $('#dnstapGeodatStatus').text(
                        '{{ lang._("Categories in file:") }} ' + n
                    );
                    dnstapGeodatFillModal(data.categories || []);
                },
                error: function (xhr, status) {
                    if (status === 'abort') {
                        return;
                    }
                    $('#dnstapGeodatStatus').text('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        }

        $(document).on('change', '#dnstapGeodatUrlPick', function () {
            if (dnstapGeodatPickSilent) {
                return;
            }
            dnstapGeodatLoad(String($(this).val() || ''));
        });

        $(document).on('click', '.dnstap-geodat-category', function () {
            var urls = dnstapCollectGeodatUrls().filter(function (row) {
                return String(row.value || '').trim() !== '';
            });
            if (!urls.length) {
                alert('{{ lang._("Add a geosite.dat URL first.") }}');
                return;
            }
            $('#dnstapGeodatModal').modal('show');
            dnstapGeodatFillUrlPick(urls[0].value);
            dnstapGeodatLoad(dnstapGeodatActiveUrl);
        });

        $(document).on('click', '#dnstapGeodatSave', function () {
            var urls = dnstapCollectGeodatUrls();
            var url = dnstapGeodatActiveUrl || String($('#dnstapGeodatUrlPick').val() || '');
            var cats = dnstapGeodatCollectCats();
            var $btn = $(this).prop('disabled', true);
            $('#dnstapGeodatStatus').text('{{ lang._("Saving categories…") }}');
            $.ajax({
                url: '/api/xray/service/dnstapgeodat',
                type: 'POST',
                dataType: 'json',
                timeout: 180000,
                data: {
                    geodat_urls: JSON.stringify(urls),
                    geodat_url: url,
                    geodat_categories: JSON.stringify(cats)
                },
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (!data || data.result === 'failed') {
                        $('#dnstapGeodatStatus').text(data && data.message
                            ? data.message
                            : '{{ lang._("Failed to save categories") }}');
                        return;
                    }
                    dnstapGeodatUrlsCache = urls;
                    $('#dnstapGeodatModal').modal('hide');
                    loadDnstapConf();
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $('#dnstapGeodatStatus').text('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        });

        $(document).on('click', '#dnstapStart', function () {
            var $btn = $(this).prop('disabled', true);
            ajaxCall('/api/xray/service/dnstapstart', {}, function (data) {
                $btn.prop('disabled', false);
                loadDnstapConf();
                if (!data || data.result === 'failed') {
                    alert(data && data.message ? data.message : '{{ lang._("Failed to start dnstap-bgp") }}');
                }
            });
        });
        function dnstapExtraInput(kind) {
            if (kind === 'direct') {
                return $('#domainsDirectInput');
            }
            if (kind === 'block') {
                return $('#domainsBlockInput');
            }
            return $('#domainsProxyInput');
        }

        function dnstapExtraList(kind) {
            if (kind === 'direct') {
                return dnstapExtraDirect;
            }
            if (kind === 'block') {
                return dnstapExtraBlock;
            }
            return dnstapExtraProxy;
        }

        function dnstapExtraSet(kind, list) {
            if (kind === 'direct') {
                dnstapExtraDirect = list;
            } else if (kind === 'block') {
                dnstapExtraBlock = list;
            } else {
                dnstapExtraProxy = list;
            }
        }

        function domainsExtraSetCount($el, n) {
            n = parseInt(n, 10);
            if (n > 0) {
                $el.text(String(n)).attr('title', '{{ lang._("Extra domains in the list") }}').show();
            } else {
                $el.text('').hide();
            }
        }

        function domainsExtraRefreshCounts() {
            domainsExtraSetCount($('#domainsProxyCount'), dnstapExtraProxy.length);
            domainsExtraSetCount($('#domainsDirectCount'), dnstapExtraDirect.length);
            domainsExtraSetCount($('#domainsBlockCount'), dnstapExtraBlock.length);
        }

        function dnstapExtraAdd(kind) {
            var $inp = dnstapExtraInput(kind);
            var d = dnstapNormalizeDomain($inp.val());
            if (d === '') {
                alert('{{ lang._("Enter a valid domain name.") }}');
                return;
            }
            var list = dnstapExtraList(kind);
            if (list.indexOf(d) === -1) {
                list.push(d);
            }
            dnstapExtraSet(kind, list);
            dnstapExtrasDirty = true;
            domainsExtraRefreshCounts();
            $inp.val(d);
        }

        function dnstapExtraRemove(kind, domain) {
            var $inp = dnstapExtraInput(kind);
            var d = domain ? dnstapNormalizeDomain(domain) : dnstapNormalizeDomain($inp.val());
            dnstapExtraSet(kind, dnstapExtraList(kind).filter(function (x) { return x !== d; }));
            dnstapExtrasDirty = true;
            domainsExtraRefreshCounts();
            if ($inp.val() && dnstapNormalizeDomain($inp.val()) === d) {
                $inp.val('');
            }
        }

        function dnstapRenderDomainList(filter) {
            var list = dnstapExtraList(domainsListKind);
            var q = String(filter || '').toLowerCase();
            var shown = 0;
            var $tb = $('#domainsListRows').empty();
            list.slice().sort().forEach(function (d) {
                if (q && d.indexOf(q) === -1) {
                    return;
                }
                shown++;
                var $tr = $('<tr/>').css('cursor', 'pointer');
                $tr.append($('<td/>').text(d));
                var $rm = $('<button type="button" class="btn btn-xs btn-default"/>')
                    .attr('title', '{{ lang._("Remove") }}')
                    .append($('<span class="fa fa-fw fa-minus"/>'));
                $rm.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dnstapExtraRemove(domainsListKind, d);
                    dnstapRenderDomainList($('#domainsListFilter').val());
                });
                $tr.append($('<td/>').css({'width': '2.5em', 'text-align': 'right', 'white-space': 'nowrap'}).append($rm));
                $tr.on('click', function () {
                    dnstapExtraInput(domainsListKind).val(d);
                    $('#domainsListModal').modal('hide');
                });
                $tb.append($tr);
            });
            $('#domainsListCount').text(shown + ' / ' + list.length);
        }

        function dnstapOpenDomainList(kind) {
            domainsListMode = 'domain';
            domainsListKind = kind;
            var title = '{{ lang._("Extra PROXY domains") }}';
            if (kind === 'direct') {
                title = '{{ lang._("Extra DIRECT domains") }}';
            } else if (kind === 'block') {
                title = '{{ lang._("Extra BLOCK domains") }}';
            }
            $('#domainsListModalTitle').text(title);
            $('#domainsListFilter').val('');
            dnstapRenderDomainList('');
            $('#domainsListModal').modal('show');
        }

        function domainsRenderRouteRows(rows) {
            var $tb = $('#domainsCheckRows').empty();
            (rows || []).forEach(function (r) {
                $tb.append(
                    '<tr><td>' + escAttr(r.domain || '') + '</td>'
                    + '<td>' + escAttr(r.ip || '') + '</td>'
                    + '<td>' + escAttr(r.peer || 'N/A') + '</td>'
                    + '<td>' + escAttr(r.class || '') + '</td>'
                    + '<td>' + escAttr(r.list_class || '') + '</td>'
                    + '<td>' + escAttr(r.instance || 'N/A') + '</td>'
                    + '<td>' + escAttr(r.dev || 'WAN4') + '</td>'
                    + '<td>' + (r.active ? '{{ lang._("yes") }}' : '{{ lang._("no") }}') + '</td></tr>'
                );
            });
        }

        function domainsCheckRoute() {
            var d = $.trim($('#domainsCheckInput').val() || '');
            var $btn = $('#domainsCheckBtn');
            var $msg = $('#domainsCheckMsg');
            var $tbl = $('#domainsCheckTable');
            if (!d) {
                $tbl.hide();
                $msg.text('{{ lang._("Enter a domain or IP") }}').show();
                return;
            }
            $btn.prop('disabled', true);
            $msg.text('{{ lang._("Checking…") }}').show();
            ajaxCall('/api/xray/service/domainroute', { domain: d }, function (data) {
                $btn.prop('disabled', false);
                var rows = data && data.rows ? data.rows : [];
                domainsRenderRouteRows(rows);
                if (rows.length) {
                    $tbl.show();
                    if (data && data.hint) {
                        $msg.text(data.hint).show();
                    } else {
                        $msg.hide();
                    }
                } else {
                    $tbl.hide();
                    $msg.text((data && data.message) ? data.message : '{{ lang._("No routes") }}').show();
                }
            });
        }

        $(document).on('click', '#domainsCheckBtn', function () { domainsCheckRoute(); });
        $('#domainsCheckInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                domainsCheckRoute();
            }
        });

        function staticExtraList(kind) {
            if (kind === 'direct') {
                return staticExtraDirect;
            }
            if (kind === 'block') {
                return staticExtraBlock;
            }
            return staticExtraProxy;
        }

        function staticExtraInput(kind) {
            if (kind === 'direct') {
                return $('#domainsIpDirectInput');
            }
            if (kind === 'block') {
                return $('#domainsIpBlockInput');
            }
            return $('#domainsIpProxyInput');
        }

        function staticApplyExtra(data, force) {
            if (staticExtraDirty && !force) {
                return;
            }
            if (!data || !data.extra) {
                return;
            }
            var extra = data.extra;
            staticExtraProxy = $.isArray(extra.proxy) ? extra.proxy.slice() : [];
            staticExtraDirect = $.isArray(extra.direct) ? extra.direct.slice() : [];
            staticExtraBlock = $.isArray(extra.block) ? extra.block.slice() : [];
            domainsIpRefreshCounts();
        }

        function staticExtraSet(kind, list) {
            if (kind === 'direct') {
                staticExtraDirect = list;
            } else if (kind === 'block') {
                staticExtraBlock = list;
            } else {
                staticExtraProxy = list;
            }
        }

        function staticPrefixNormalize(raw) {
            var s = String(raw || '').trim().replace(/,+$/, '');
            var bracket = s.match(/^\[([^\]]+)\](?:\/(\d{1,3}))?$/);
            if (bracket) {
                s = bracket[1] + (bracket[2] ? '/' + bracket[2] : '');
            }
            if (s.indexOf(':') >= 0) {
                s = s.toLowerCase();
                if (s.indexOf('/') < 0) {
                    s += '/128';
                }
                return s;
            }
            if (s.indexOf('/') < 0) {
                s += '/32';
            }
            return s;
        }

        function staticExtraWritePayload() {
            return {
                proxy: JSON.stringify(staticExtraProxy),
                direct: JSON.stringify(staticExtraDirect),
                block: JSON.stringify(staticExtraBlock)
            };
        }

        function domainsIpSetCount($el, n) {
            n = parseInt(n, 10);
            if (n > 0) {
                $el.text(String(n)).attr('title', '{{ lang._("Extra prefixes in the list") }}').show();
            } else {
                $el.text('').hide();
            }
        }

        function domainsIpRefreshCounts() {
            domainsIpSetCount($('#domainsIpProxyCount'), staticExtraProxy.length);
            domainsIpSetCount($('#domainsIpDirectCount'), staticExtraDirect.length);
            domainsIpSetCount($('#domainsIpBlockCount'), staticExtraBlock.length);
        }

        function loadStaticExtra() {
            if (staticExtraBusy) {
                return;
            }
            var seq = ++staticExtraSeq;
            ajaxGet('/api/xray/service/staticconf', {}, function (data) {
                if (seq !== staticExtraSeq || !data || data.result === 'failed') {
                    return;
                }
                staticApplyExtra(data);
                staticSetCounts(data.counts);
            });
        }

        function staticPrefixLooksValid(raw) {
            var s = String(raw || '').trim().replace(/,+$/, '');
            var bracket = s.match(/^\[([^\]]+)\](?:\/(\d{1,3}))?$/);
            if (bracket) {
                s = bracket[1] + (bracket[2] ? '/' + bracket[2] : '');
            }
            if (!s || /[\s,]/.test(s)) {
                return false;
            }
            if (s.indexOf(':') >= 0) {
                var bits6 = s.split('/');
                if (bits6.length > 2) {
                    return false;
                }
                if (bits6.length === 2 && (!/^\d{1,3}$/.test(bits6[1]) || parseInt(bits6[1], 10) > 128)) {
                    return false;
                }
                return /^[0-9a-fA-F:]+$/.test(bits6[0]) && bits6[0].indexOf(':') >= 0;
            }
            var m = s.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/);
            if (!m) {
                return false;
            }
            for (var i = 1; i <= 4; i++) {
                if (parseInt(m[i], 10) > 255 || /^0\d/.test(m[i])) {
                    return false;
                }
            }
            return !(m[5] != null && parseInt(m[5], 10) > 32);
        }

        function staticExtraSetBusy(on) {
            staticExtraBusy = on;
            $('#domainsIpProxyAdd, #domainsIpProxyRemove, #domainsIpProxySearch,'
                + '#domainsIpDirectAdd, #domainsIpDirectRemove, #domainsIpDirectSearch,'
                + '#domainsIpBlockAdd, #domainsIpBlockRemove, #domainsIpBlockSearch,'
                + '.domains-extra-save[data-extra="ip"]')
                .prop('disabled', on);
        }

        function staticExtraMutate(action, kind, explicit) {
            var raw = explicit != null ? String(explicit) : String(staticExtraInput(kind).val() || '');
            raw = $.trim(raw);
            if (!staticPrefixLooksValid(raw)) {
                alert('{{ lang._("Enter an IPv4/IPv6 address or subnet.") }}');
                return;
            }
            var prefix = staticPrefixNormalize(raw);
            var list = staticExtraList(kind).slice();
            var $inp = staticExtraInput(kind);
            if (action === 'add') {
                if (list.indexOf(prefix) === -1) {
                    list.push(prefix);
                }
                $inp.val(prefix);
            } else {
                list = list.filter(function (x) {
                    return x !== raw && x !== prefix && staticPrefixNormalize(x) !== prefix;
                });
                var cur = $.trim($inp.val() || '');
                if (cur === raw || cur === prefix || (cur && staticPrefixNormalize(cur) === prefix)) {
                    $inp.val('');
                }
            }
            staticExtraSet(kind, list);
            staticExtraDirty = true;
            domainsIpRefreshCounts();
            if ($('#domainsListModal').is(':visible') && domainsListMode === 'ip' && domainsListKind === kind) {
                staticRenderIpList($('#domainsListFilter').val());
            }
        }

        function staticRenderIpList(filter) {
            var list = staticExtraList(domainsListKind);
            var q = String(filter || '').toLowerCase();
            var shown = 0;
            var $tb = $('#domainsListRows').empty();
            list.slice().forEach(function (p) {
                if (q && String(p).toLowerCase().indexOf(q) === -1) {
                    return;
                }
                shown++;
                var $tr = $('<tr/>').css('cursor', 'pointer');
                $tr.append($('<td/>').text(p));
                var $rm = $('<button type="button" class="btn btn-xs btn-default"/>')
                    .attr('title', '{{ lang._("Remove") }}')
                    .prop('disabled', staticExtraBusy)
                    .append($('<span class="fa fa-fw fa-minus"/>'));
                $rm.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    staticExtraMutate('remove', domainsListKind, p);
                });
                $tr.append($('<td/>').css({'width': '2.5em', 'text-align': 'right', 'white-space': 'nowrap'}).append($rm));
                $tr.on('click', function () {
                    staticExtraInput(domainsListKind).val(p);
                    $('#domainsListModal').modal('hide');
                });
                $tb.append($tr);
            });
            $('#domainsListCount').text(shown + ' / ' + list.length);
        }

        function staticOpenIpList(kind) {
            domainsListMode = 'ip';
            domainsListKind = kind;
            var title = '{{ lang._("Extra PROXY IP") }}';
            if (kind === 'direct') {
                title = '{{ lang._("Extra DIRECT IP") }}';
            } else if (kind === 'block') {
                title = '{{ lang._("Extra BLOCK IP") }}';
            }
            $('#domainsListModalTitle').text(title);
            $('#domainsListFilter').val('');
            staticRenderIpList('');
            $('#domainsListModal').modal('show');
        }

        $(document).on('click', '.domains-extra-save', function () {
            var kind = String($(this).attr('data-kind') || 'proxy');
            if ($(this).attr('data-extra') === 'ip') {
                staticExtraMutate('add', kind);
                return;
            }
            dnstapExtraAdd(kind);
        });
        $(document).on('click', '#domainsProxyAdd', function () { dnstapExtraAdd('proxy'); });
        $(document).on('click', '#domainsDirectAdd', function () { dnstapExtraAdd('direct'); });
        $(document).on('click', '#domainsBlockAdd', function () { dnstapExtraAdd('block'); });
        $(document).on('click', '#domainsProxyRemove', function () { dnstapExtraRemove('proxy'); });
        $(document).on('click', '#domainsDirectRemove', function () { dnstapExtraRemove('direct'); });
        $(document).on('click', '#domainsBlockRemove', function () { dnstapExtraRemove('block'); });
        $(document).on('click', '#domainsProxySearch', function () { dnstapOpenDomainList('proxy'); });
        $(document).on('click', '#domainsDirectSearch', function () { dnstapOpenDomainList('direct'); });
        $(document).on('click', '#domainsBlockSearch', function () { dnstapOpenDomainList('block'); });
        $(document).on('click', '#domainsIpProxyAdd', function () { staticExtraMutate('add', 'proxy'); });
        $(document).on('click', '#domainsIpDirectAdd', function () { staticExtraMutate('add', 'direct'); });
        $(document).on('click', '#domainsIpBlockAdd', function () { staticExtraMutate('add', 'block'); });
        $(document).on('click', '#domainsIpProxyRemove', function () { staticExtraMutate('remove', 'proxy'); });
        $(document).on('click', '#domainsIpDirectRemove', function () { staticExtraMutate('remove', 'direct'); });
        $(document).on('click', '#domainsIpBlockRemove', function () { staticExtraMutate('remove', 'block'); });
        $(document).on('click', '#domainsIpProxySearch', function () { staticOpenIpList('proxy'); });
        $(document).on('click', '#domainsIpDirectSearch', function () { staticOpenIpList('direct'); });
        $(document).on('click', '#domainsIpBlockSearch', function () { staticOpenIpList('block'); });
        $(document).on('input', '#domainsListFilter', function () {
            if (domainsListMode === 'ip') {
                staticRenderIpList($(this).val());
            } else {
                dnstapRenderDomainList($(this).val());
            }
        });
        $('#domainsProxyInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                dnstapExtraAdd('proxy');
            }
        });
        $('#domainsDirectInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                dnstapExtraAdd('direct');
            }
        });
        $('#domainsBlockInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                dnstapExtraAdd('block');
            }
        });
        $('#domainsIpProxyInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                staticExtraMutate('add', 'proxy');
            }
        });
        $('#domainsIpDirectInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                staticExtraMutate('add', 'direct');
            }
        });
        $('#domainsIpBlockInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                staticExtraMutate('add', 'block');
            }
        });

        $(document).on('click', '#dnstapStop', function () {
            var $btn = $(this).prop('disabled', true);
            ajaxCall('/api/xray/service/dnstapstop', {}, function (data) {
                $btn.prop('disabled', false);
                loadDnstapConf();
                if (!data || data.result === 'failed') {
                    alert(data && data.message ? data.message : '{{ lang._("Failed to stop dnstap-bgp") }}');
                }
            });
        });

        // ── General settings form ───────────────────────────────────
        function isGeneralTab() {
            return $('#general').filter('.active').length > 0;
        }

        function routingStrategySelect() {
            var $s = $('#frm_general_settings [id="general.routing_strategy"]');
            if (!$s.length) {
                $s = $('select[id="general.routing_strategy"]');
            }
            if (!$s.length) {
                $s = $('select').filter(function () {
                    return String(this.id || '').indexOf('routing_strategy') >= 0;
                }).first();
            }
            return $s;
        }

        function routingStrategyValue() {
            return String(routingStrategySelect().val() || '');
        }

        function ensureRoutingStrategyWarnEl() {
            var $sel = routingStrategySelect();
            if (!$sel.length) {
                return $();
            }
            var $host = $sel.closest('td');
            if (!$host.length) {
                $host = $sel.parent();
            }
            var $w = $('#xrayRoutingStrategyWarn');
            if (!$w.length) {
                $w = $('<div id="xrayRoutingStrategyWarn" class="text-warning" style="display:none;margin-top:6px;font-size:12px;line-height:1.4;"></div>');
                $host.append($w);
            }
            return $w;
        }

        function refreshRoutingStrategyAssignmentWarn() {
            var $w = ensureRoutingStrategyWarnEl();
            var strat = routingStrategyValue();
            if (strat === 'system_policy' || strat === '') {
                $w.hide().empty();
                return $.Deferred().resolve({ok: true, missing: []}).promise();
            }
            return $.ajax({
                url: '/api/xray/service/tunassignments',
                type: 'GET',
                dataType: 'json'
            }).then(function (data) {
                var missing = (data && $.isArray(data.missing)) ? data.missing : [];
                if (!missing.length) {
                    $w.hide().empty();
                    return {ok: true, missing: []};
                }
                var list = missing.map(function (m) {
                    return (m.name || m.tun) + ' (' + m.tun + ')';
                }).join(', ');
                $w.html(
                    '{{ lang._("Warning: assign these instance interfaces in Interfaces → Assignments before using this routing strategy:") }} '
                    + $('<div/>').text(list).html()
                ).show();
                return {ok: false, missing: missing};
            }, function () {
                return {ok: true, missing: []};
            });
        }

        mapDataToFormUI({'frm_general_settings': "/api/xray/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            refreshRoutingStrategyAssignmentWarn();
            $(document).on('changed.bs.select change', 'select[id="general.routing_strategy"]', function () {
                refreshRoutingStrategyAssignmentWarn();
            });
        });

        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            if ($(e.target).attr('href') === '#general') {
                refreshRoutingStrategyAssignmentWarn();
            }
        });

        // ── Apply: routing → bgpwrite; DNStap → files; otherwise general + reconfigure ──
        setApplyEndpoint();
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                var dfObj = new $.Deferred();
                if (isBgpRoutingTab()) {
                    dfObj.resolve();
                    return dfObj;
                }
                if (isStaticTab()) {
                    $.ajax({
                        url: '/api/xray/service/staticwrite',
                        type: 'POST',
                        dataType: 'json',
                        data: staticWritePayload(),
                        success: function (data) {
                            if (data && data.result === 'failed') {
                                alert('{{ lang._("Failed to write Static IP lists:") }} '
                                    + (data.message || 'unknown error'));
                                dfObj.reject();
                                return;
                            }
                            loadStaticConf();
                            dfObj.resolve();
                        },
                        error: function (xhr) {
                            alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                            dfObj.reject();
                        }
                    });
                    return dfObj;
                }
                if (isDnstapTab() || isDomainsTab()) {
                    $.ajax({
                        url: '/api/xray/service/dnstapwrite',
                        type: 'POST',
                        dataType: 'json',
                        data: dnstapWritePayload(),
                        success: function (data) {
                            if (data && data.result === 'failed') {
                                alert('{{ lang._("Failed to write dnstap-bgp config:") }} '
                                    + (data.message || 'unknown error'));
                                dfObj.reject();
                                return;
                            }
                            dnstapExtrasDirty = false;
                            loadDnstapConf();
                            if (!isDomainsTab() || !staticExtraDirty) {
                                dfObj.resolve();
                                return;
                            }
                            $.ajax({
                                url: '/api/xray/service/staticextrawrite',
                                type: 'POST',
                                dataType: 'json',
                                timeout: 180000,
                                data: staticExtraWritePayload(),
                                success: function (extraData) {
                                    if (!extraData || extraData.result === 'failed') {
                                        alert('{{ lang._("Failed to write Extra IP lists:") }} '
                                            + ((extraData && extraData.message) || 'unknown error'));
                                        dfObj.reject();
                                        return;
                                    }
                                    staticExtraDirty = false;
                                    staticApplyExtra(extraData, true);
                                    staticSetCounts(extraData.counts);
                                    if (extraData.message && /configure failed|ERROR/i.test(extraData.message)) {
                                        alert(extraData.message);
                                    }
                                    dfObj.resolve();
                                },
                                error: function (xhr) {
                                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                                    dfObj.reject();
                                }
                            });
                        },
                        error: function (xhr) {
                            alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                            dfObj.reject();
                        }
                    });
                    return dfObj;
                }
                // General Apply: warn under Routing strategy if TUNs not assigned
                refreshRoutingStrategyAssignmentWarn().always(function () {
                    saveFormToEndpoint("/api/xray/general/set", 'frm_general_settings', function () {
                        dfObj.resolve();
                    });
                });
                return dfObj;
            },
            onAction: function () {
                // Clear pending-change banners (incl. after fast OK from background reconfigure)
                $('#InstanceChangeMessage, #BgpPeerChangeMessage, #BgpFilterChangeMessage, #BgpCommunityChangeMessage')
                    .hide();
                $('.alert[id$="ChangeMessage"]').hide();
                reloadBgpPeersGrid();
                if (isBgpRoutingTab()) {
                    openRoutingPeersStatus();
                }
                if (isStaticTab()) {
                    loadStaticConf();
                }
                if (isDnstapTab() || isDomainsTab()) {
                    loadDnstapConf();
                }
                if (isGeneralTab()) {
                    refreshRoutingStrategyAssignmentWarn();
                }
                setTimeout(function () { refreshInstancesStatus(); }, 2000);
            }
        });

        // ── Status badges + per-instance status ───────────────────
        function refreshInstancesStatus() {
            ajaxGet("/api/xray/service/statusall", {}, function (data) {
                if (!data || typeof data !== 'object' || data.error) return;
                instanceStatusCache = data;

                // Aggregate: any instance running = global running
                var anyXray = false, anyTun = false;
                $.each(data, function (uuid, info) {
                    if (!info || typeof info !== 'object' || Array.isArray(info)) {
                        return;
                    }
                    if (info.xray_core === 'running') anyXray = true;
                    if (info.tun2socks === 'running') anyTun = true;
                    // Last IF result (cron or manual) — whichever wrote the store last per uuid
                    if (info.test_result && info.test_result.message) {
                        instanceTestCache[uuid] = {
                            result: info.test_result.result || 'failed',
                            message: info.test_result.message,
                            http_code: info.test_result.http_code || 0,
                            source: info.test_result.source || ''
                        };
                    }
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

                // Update per-instance status + Test Result in grid
                applyStatusToGrid();
                applyTestResultToGrid();

                var running = xok || tok;
                $('#btnStartAll').prop('disabled', running);
                $('#btnStopAll').prop('disabled', !running);
                $('#btnRestartAll').prop('disabled', !running);
            });
        }
        refreshInstancesStatus();
        setInterval(refreshInstancesStatus, 5000);
        loadDnstapConf();

        // ── Start / Stop / Restart ──────────────────────────────────
        function serviceAction(action, confirmMsg, callback) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            // TODO: clean up — pass $btn as argument instead of deriving it from action string
            var $btns = $('#btnStartAll, #btnStopAll, #btnRestartAll').prop('disabled', true);
            var $btn = action === 'start'   ? $('#btnStartAll')
                     : action === 'stop'    ? $('#btnStopAll')
                     :                        $('#btnRestartAll');
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/xray/service/' + action,
                type:     'POST',
                dataType: 'json',
                timeout:  180000,
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ lang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    setTimeout(function () {
                        refreshInstancesStatus();
                        $btns.prop('disabled', false);
                        if (callback) callback();
                    }, 1500);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    // 504: gateway timed out while start may still have succeeded
                    if (xhr.status === 504 || xhr.status === 502) {
                        setTimeout(function () { refreshInstancesStatus(); }, 1500);
                        alert('{{ lang._("Request timed out — check instance status; services may still have started.") }}');
                        return;
                    }
                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        }

        $('#btnStartAll').click(function () {
            serviceAction('start', null, null);
        });
        $('#btnStopAll').click(function () {
            var confirmStop = '{{ lang._("Stop Xray VPN? Active connections will be terminated.") }}';
            serviceAction('stop', confirmStop, null);
        });
        $('#btnRestartAll').click(function () {
            serviceAction('restart', null, null);
        });

        // ── Test all IF (SOCKS) / Test auto route (separate) ─────────
        function xrayEnabledRunningUuids() {
            var uuids = [];
            $.each(instanceStatusCache, function (uuid, info) {
                if (info.enabled === false || info.enabled === 0 || info.enabled === '0') {
                    return;
                }
                if (info.xray_core === 'running') {
                    uuids.push(uuid);
                }
            });
            return uuids;
        }

        var testResultClearTimer = null;
        function clearTestConnectResultSoon($res, delayMs) {
            if (testResultClearTimer) {
                clearTimeout(testResultClearTimer);
                testResultClearTimer = null;
            }
            testResultClearTimer = setTimeout(function () {
                testResultClearTimer = null;
                $res.text('').removeClass('text-success text-danger');
            }, delayMs || 30000);
        }
        function setTestConnectResult($res, ok, message, autoClear) {
            if (testResultClearTimer) {
                clearTimeout(testResultClearTimer);
                testResultClearTimer = null;
            }
            if (ok) {
                $res.removeClass('text-danger').addClass('text-success').text(message);
            } else {
                $res.removeClass('text-success').addClass('text-danger').text(message);
            }
            if (autoClear !== false) {
                clearTestConnectResultSoon($res, 30000);
            }
        }

        $('#btnTestConnect').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#testConnectResult');
            var uuids = xrayEnabledRunningUuids();

            if (!uuids.length) {
                setTestConnectResult($res, false, "{{ lang._('No enabled running instances.') }}");
                $btn.prop('disabled', false);
                return;
            }

            if (testResultClearTimer) {
                clearTimeout(testResultClearTimer);
                testResultClearTimer = null;
            }
            $res.removeClass('text-success text-danger').text("{{ lang._('Testing IF...') }}");
            var pending = uuids.length;
            var anyFail = false;

            $.each(uuids, function (_, uuid) {
                $.ajax({
                    url: '/api/xray/service/testconnect/' + encodeURIComponent(uuid),
                    type: 'POST', dataType: 'json',
                    timeout: 60000,
                    success: function (data) {
                        instanceTestCache[uuid] = data;
                        applyTestResultToGrid();
                        if (!(data && data.result === 'ok')) {
                            anyFail = true;
                        }
                    },
                    error: function () {
                        anyFail = true;
                    },
                    complete: function () {
                        pending--;
                        if (pending === 0) {
                            $btn.prop('disabled', false);
                            if (anyFail) {
                                setTestConnectResult(
                                    $res,
                                    false,
                                    "{{ lang._('IF test finished with errors — see Test column.') }}"
                                );
                            } else {
                                setTestConnectResult(
                                    $res,
                                    true,
                                    "{{ lang._('IF test OK.') }}"
                                );
                            }
                        }
                    }
                });
            });
        });

        var autoRoutePollTimer = null;
        function stopAutoRoutePoll() {
            if (autoRoutePollTimer) {
                clearInterval(autoRoutePollTimer);
                autoRoutePollTimer = null;
            }
        }
        function finishAutoRouteUi($btn, $res, ok, message) {
            stopAutoRoutePoll();
            $btn.prop('disabled', false);
            setTestConnectResult(
                $res,
                ok,
                message || (ok
                    ? "{{ lang._('Auto route OK.') }}"
                    : "{{ lang._('Auto route failed.') }}")
            );
            if (ok) {
                reloadBgpPeersGrid();
                refreshInstancesStatus();
            }
        }
        function pollAutoRouteStatus($btn, $res, token, startedMs) {
            var tries = 0;
            var maxTries = 90; // ~3 min at 2s
            stopAutoRoutePoll();
            autoRoutePollTimer = setInterval(function () {
                tries++;
                ajaxGet('/api/xray/service/birdactivetunstatus', {}, function (st) {
                    if (!st) {
                        return;
                    }
                    var sameToken = !token || String(st.token || '') === String(token);
                    var done = sameToken && st.running === false && st.phase === 'done'
                        && Number(st.finished_at || 0) * 1000 >= startedMs - 2000;
                    if (done) {
                        finishAutoRouteUi(
                            $btn,
                            $res,
                            st.result === 'ok',
                            st.message || (st.result === 'ok'
                                ? "{{ lang._('Auto route OK.') }}"
                                : "{{ lang._('Auto route failed.') }}")
                        );
                        return;
                    }
                    if (sameToken && st.running) {
                        $res.removeClass('text-success text-danger')
                            .text(st.message || "{{ lang._('Auto route...') }}");
                    }
                });
                if (tries >= maxTries) {
                    finishAutoRouteUi(
                        $btn,
                        $res,
                        false,
                        "{{ lang._('Auto route timed out — see Autoroute Log.') }}"
                    );
                }
            }, 2000);
        }

        $('#btnTestAutoRoute').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#testConnectResult');
            var startedMs = Date.now();
            stopAutoRoutePoll();
            if (testResultClearTimer) {
                clearTimeout(testResultClearTimer);
                testResultClearTimer = null;
            }
            $res.removeClass('text-success text-danger')
                .text("{{ lang._('Auto route...') }}");
            $.ajax({
                url: '/api/xray/service/birdactivetun',
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                success: function (data) {
                    if (data && data.result === 'ok' && data.async) {
                        $res.removeClass('text-success text-danger')
                            .text("{{ lang._('Auto route...') }}");
                        pollAutoRouteStatus($btn, $res, data.token || '', startedMs);
                        return;
                    }
                    var msg = (data && data.message) ? String(data.message) : '';
                    var lines = msg.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
                    var short = lines.slice(0, 2).join(' ')
                        .replace(/\s*RESULT:(OK|ERROR)\s*/g, '').trim();
                    finishAutoRouteUi(
                        $btn,
                        $res,
                        !!(data && data.result === 'ok'),
                        short
                    );
                },
                error: function (xhr) {
                    finishAutoRouteUi(
                        $btn,
                        $res,
                        false,
                        "{{ lang._('Auto route failed.') }} HTTP " + (xhr.status || '?')
                    );
                }
            });
        });

        // ── Import VLESS (inside DialogInstance) ──────────────────
        function applyImportToDialog(data) {
            var $dlg = $('#DialogInstance');
            if (data.name) {
                $dlg.find('[id="instance.name"]').val(data.name);
            }
            $dlg.find('[id="instance.outbound_config"]').val(data.outbound_config || '');
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

            $.ajax({
                url:         '/api/xray/import/parse',
                type:        'POST',
                contentType: 'application/json; charset=utf-8',
                data:        JSON.stringify({link_b64: b64}),
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
        function populateInstanceSelects() {
            $.ajax({
                url: '/api/xray/instance/searchItem',
                type: 'POST',
                dataType: 'json',
                data: {rowCount: -1, current: 1, searchPhrase: ''},
                success: function (data) {
                    var rows = (data && data.rows) ? data.rows : [];
                    var $diagSel = $('#diagInstanceSelect');
                    var $logSel = $('#logInstanceSelect');
                    var savedDiag = $diagSel.val();
                    var savedLog = $logSel.val();
                    $diagSel.empty();
                    $logSel.empty();
                    $.each(rows, function (_, row) {
                        $diagSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                        $logSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                    });
                    if (savedDiag && $diagSel.find('option[value="' + savedDiag + '"]').length) {
                        $diagSel.val(savedDiag);
                    }
                    if (savedLog && $logSel.find('option[value="' + savedLog + '"]').length) {
                        $logSel.val(savedLog);
                    }
                }
            });
        }

        function loadDiagnostics() {
            var uuid = $('#diagInstanceSelect').val();
            var url = '/api/xray/service/diagnostics' + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#btnDiagRefresh').prop('disabled', true);
            $('#diagError').hide();
            ajaxGet(url, {}, function (data) {
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
                $('#diag_tun_ip6').text(data.tun_ip6         || '\u2014');
                $('#diag_ip_stack').text(data.ip_stack       || '\u2014');
                $('#diag_dns_servers').text(data.dns_servers || '\u2014');
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
        $('#diagInstanceSelect').on('change', function () {
            loadDiagnostics();
        });
        $('#logInstanceSelect').on('change', function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
        });

        // ── Logs ────────────────────────────────────────────────────
        function loadLog(apiEndpoint, preId, btnId, withUuid) {
            var uuid = withUuid ? $('#logInstanceSelect').val() : '';
            var apiEndpointWithUuid = apiEndpoint + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#' + btnId).prop('disabled', true);
            $('#' + preId).text("{{ lang._('Loading...') }}");
            $.post(apiEndpointWithUuid, null, function (data) {
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
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn', false);
            } else if (href === '#logAutoroute') {
                loadLog("/api/xray/service/autoroutelog", 'logAutorouteContent', 'logAutorouteRefreshBtn', false);
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
            } else if (href === '#logBird') {
                loadBirdLogLevel();
                loadLog("/api/xray/service/birdlog", 'logBirdContent', 'logBirdRefreshBtn', false);
            } else if (href === '#logDnstap') {
                loadLog("/api/xray/service/dnstaplog", 'logDnstapContent', 'logDnstapRefreshBtn', false);
            }
        });

        $('#logSubTabs a').on('shown.bs.tab', function (e) {
            var href = $(e.target).attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn', false);
            } else if (href === '#logAutoroute') {
                loadLog("/api/xray/service/autoroutelog", 'logAutorouteContent', 'logAutorouteRefreshBtn', false);
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
            } else if (href === '#logBird') {
                loadBirdLogLevel();
                loadLog("/api/xray/service/birdlog", 'logBirdContent', 'logBirdRefreshBtn', false);
            } else if (href === '#logDnstap') {
                loadLog("/api/xray/service/dnstaplog", 'logDnstapContent', 'logDnstapRefreshBtn', false);
            }
        });

        $("#logBootRefreshBtn").click(function () {
            loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn', false);
        });
        $("#logAutorouteRefreshBtn").click(function () {
            loadLog("/api/xray/service/autoroutelog", 'logAutorouteContent', 'logAutorouteRefreshBtn', false);
        });
        $("#logCoreRefreshBtn").click(function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
        });
        $("#logBirdRefreshBtn").click(function () {
            loadLog("/api/xray/service/birdlog", 'logBirdContent', 'logBirdRefreshBtn', false);
        });
        $("#logDnstapRefreshBtn").click(function () {
            loadLog("/api/xray/service/dnstaplog", 'logDnstapContent', 'logDnstapRefreshBtn', false);
        });

        function loadBirdLogLevel() {
            ajaxGet('/api/xray/service/birdloglevel', {}, function (data) {
                var level = (data && data.level) ? data.level : 'warning';
                $('#logBirdLevelSelect').val(level);
            });
        }

        $('#logBirdLevelSelect').on('change', function () {
            var level = $(this).val();
            var $sel = $(this).prop('disabled', true);
            $.ajax({
                url: '/api/xray/service/birdloglevel',
                type: 'POST',
                dataType: 'json',
                data: { level: level },
                success: function (data) {
                    if (data && data.result === 'failed') {
                        alert('{{ lang._("Failed to set BIRD log class:") }} ' + (data.message || 'unknown error'));
                        loadBirdLogLevel();
                        return;
                    }
                    loadLog("/api/xray/service/birdlog", 'logBirdContent', 'logBirdRefreshBtn', false);
                },
                error: function (xhr) {
                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                    loadBirdLogLevel();
                },
                complete: function () {
                    $sel.prop('disabled', false);
                }
            });
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
                    + "--- Boot Log (last 300 lines) ---\n"
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

        // ── Tab selection ──
        // Left menu uses /ui/xray/page/show/<tab> (path stays in REQUEST_URI so
        // OPNsense keeps VPN→Xray expanded). Legacy ?tab= / #hash still work.
        var xrayInitialTab = '{{ xrayInitialTab|default("instances") }}';

        function xrayTabFromHref(href) {
            href = String(href || '');
            var m = href.match(/\/ui\/xray\/page\/show\/([^\/?#]+)/);
            if (m) {
                return decodeURIComponent(m[1]);
            }
            m = href.match(/[?&]tab=([^&#]+)/);
            if (m) {
                return decodeURIComponent(m[1]);
            }
            m = href.match(/#([^?]+)/);
            if (m) {
                return m[1];
            }
            return '';
        }

        function xrayTabFromLocation() {
            var tab = xrayTabFromHref(window.location.pathname + window.location.search + window.location.hash);
            if (tab) {
                if (tab === 'routing') {
                    return 'routing-peers';
                }
                return tab;
            }
            if (xrayInitialTab) {
                return String(xrayInitialTab);
            }
            return 'instances';
        }

        function xrayEnsureLeftMenuOpen() {
            $('#mainmenu a.list-group-item[href*="/ui/xray"]').each(function () {
                $(this).parents('.collapse').addClass('in').css({height: 'auto'});
                $(this).parents('li').children('a.active-menu-title, a.list-group-item').first()
                    .addClass('active-menu-title');
            });
        }

        function xraySyncLeftMenu(tab) {
            tab = String(tab || '').replace(/^#/, '');
            $('#mainmenu a.list-group-item[href*="/ui/xray"]').each(function () {
                var item = xrayTabFromHref($(this).attr('href'));
                $(this).toggleClass('active', item !== '' && item === tab);
            });
            xrayEnsureLeftMenuOpen();
        }

        // Page header h1 is server-rendered once; SPA tab switches must update it.
        var xrayPageTitleLabels = {
            'instances': '{{ lang._("Instances") }}',
            'routing-peers': '{{ lang._("BGP peers") }}',
            'routing-filters': '{{ lang._("BGP filter") }}',
            'routing-communities': '{{ lang._("BGP community") }}',
            'routing-static': '{{ lang._("Static") }}',
            'dnstap': '{{ lang._("DNStap") }}',
            'general': '{{ lang._("General") }}',
            'domains': '{{ lang._("Extra Domain IP") }}',
            'logs': '{{ lang._("Log") }}'
        };

        function xraySyncPageTitle(tab) {
            tab = String(tab || '').replace(/^#/, '');
            var label = xrayPageTitleLabels[tab];
            if (!label) {
                return;
            }
            var $h1 = $('.page-content-header h1').first();
            if (!$h1.length) {
                $h1 = $('header h1').first();
            }
            if ($h1.length) {
                var $kids = $h1.children().detach();
                var cur = $.trim($h1.text());
                var prefix = 'VPN: Xray: ';
                var idx = cur.lastIndexOf(':');
                if (idx >= 0) {
                    prefix = cur.substring(0, idx + 1).replace(/\s*$/, '') + ' ';
                }
                $h1.text(prefix + label);
                $h1.append($kids);
            }
            var docParts = String(document.title || '').split('|');
            var hostPart = docParts.length > 1 ? ' |' + docParts.slice(1).join('|') : '';
            var headLabel = label;
            if ($h1 && $h1.length) {
                headLabel = $.trim($h1.clone().children().remove().end().text()) || label;
            }
            document.title = headLabel + hostPart;
        }

        function xrayActivateTab(tab) {
            tab = String(tab || '').replace(/^#/, '');
            if (!tab) {
                return;
            }
            var $tab = $('#maintabs a[data-toggle="tab"][href="#' + tab + '"]');
            if ($tab.length) {
                $tab.tab('show');
            }
            xraySyncLeftMenu(tab);
            xraySyncPageTitle(tab);
        }

        function xrayReplaceTabUrl(tab) {
            tab = String(tab || '').replace(/^#/, '');
            if (!tab || !window.history || !window.history.replaceState) {
                return;
            }
            history.replaceState(null, null, '/ui/xray/page/show/' + encodeURIComponent(tab));
        }

        // Switch tabs via left menu without full reload (avoids sidebar collapse flash)
        $(document).on('click', '#mainmenu a.list-group-item[href*="/ui/xray"]', function (e) {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.which === 2) {
                return;
            }
            var tab = xrayTabFromHref($(this).attr('href'));
            if (!tab || !$('#maintabs a[data-toggle="tab"][href="#' + tab + '"]').length) {
                return;
            }
            e.preventDefault();
            xrayActivateTab(tab);
            xrayReplaceTabUrl(tab);
        });

        xrayActivateTab(xrayTabFromLocation());
        $(window).on('hashchange', function () {
            xrayActivateTab(xrayTabFromLocation());
        });
        $(window).on('popstate', function () {
            xrayActivateTab(xrayTabFromLocation());
        });
        // Only main Xray tabs — not nested Log subtabs
        $('#maintabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            var tab = String(e.target.hash || '').replace(/^#/, '');
            if (tab && xrayPageTitleLabels[tab]) {
                xrayReplaceTabUrl(tab);
                xraySyncLeftMenu(tab);
                xraySyncPageTitle(tab);
            }
        });
    });
</script>
