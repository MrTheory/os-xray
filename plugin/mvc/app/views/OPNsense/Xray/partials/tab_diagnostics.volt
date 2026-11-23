<!-- DIAGNOSTICS -->
<div id="diagnostics" class="tab-pane fade in">

    {# ── Toolbar ─────────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">

                {# Instance selector #}
                <div class="input-group input-group-sm" style="min-width: 200px; width: auto;">
                    <span class="input-group-addon">
                        <i class="fa fa-server fa-fw"></i>
                    </span>
                    <select id="diagInstanceSelect" class="form-control">
                    </select>
                </div>

                <div style="width: 1px; height: 22px; background: #ddd;"></div>

                <button id="btnDiagRefresh" class="btn btn-sm btn-default">
                    <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                </button>
                <button id="btnCopyDebug" class="btn btn-sm btn-default">
                    <i class="fa fa-clipboard fa-fw"></i> {{ lang._('Copy Debug Info') }}
                </button>
                <span id="copyDebugResult" style="font-size: 12px;"></span>
                <a href="#" class="xray-showhelp" id="help_for_diagnostics"><i class="fa fa-info-circle"></i></a>
                {{ partial('OPNsense/Xray/partials/help_toggle') }}
                <div class="hidden" data-for="help_for_diagnostics" style="flex-basis: 100%;">
                    <small class="text-muted">{{ lang._('Live TUN counters for the selected instance. Copy Debug Info gathers a text snapshot for support.') }}</small>
                </div>
            </div>
        </section>
    </div>

    {# ── Stats table ─────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 12px 15px 15px;">
                <table class="table table-condensed table-striped" style="max-width: 600px;">
                    <tbody>
                        <tr>
                            <th style="width: 220px;">{{ lang._('TUN Interface') }}</th>
                            <td id="diag_tun_iface">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('TUN Status') }}</th>
                            <td id="diag_tun_status">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('TUN IP') }}</th>
                            <td id="diag_tun_ip">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('TUN IPv6') }}</th>
                            <td id="diag_tun_ip6">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('IP Stack') }}</th>
                            <td id="diag_ip_stack">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('DNS Servers') }}</th>
                            <td id="diag_dns_servers">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('MTU') }}</th>
                            <td id="diag_mtu">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Bytes In') }}</th>
                            <td id="diag_bytes_in">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Bytes Out') }}</th>
                            <td id="diag_bytes_out">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Packets In') }}</th>
                            <td id="diag_pkts_in">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Packets Out') }}</th>
                            <td id="diag_pkts_out">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('xray-core Uptime') }}</th>
                            <td id="diag_xray_uptime">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('tun2socks Uptime') }}</th>
                            <td id="diag_t2s_uptime">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Server Ping RTT') }}</th>
                            <td id="diag_ping_rtt">&mdash;</td>
                        </tr>
                    </tbody>
                </table>
                <p id="diagError" class="text-danger" style="display: none;"></p>
            </div>
        </section>
    </div>

</div>
