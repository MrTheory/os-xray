<!-- INSTANCES -->
<div id="instances" class="tab-pane fade in active">

    {# ── Service toolbar ─────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">

                {# Status badges #}
                <div>
                    <span id="badge_xray" class="label label-default">xray-core: ...</span>
                    <span id="badge_tun"  class="label label-default">tun2socks: ...</span>
                </div>

                <div style="width: 1px; height: 22px; background: #ddd;"></div>

                {# Global service controls #}
                <div style="display: flex; gap: 4px;">
                    <button id="btnStartAll" class="btn btn-xs btn-success"
                            title="{{ lang._('Start all enabled instances') }}">
                        <i class="fa fa-play fa-fw"></i> {{ lang._('Start All') }}
                    </button>
                    <button id="btnStopAll" class="btn btn-xs btn-danger"
                            title="{{ lang._('Stop all running instances') }}">
                        <i class="fa fa-stop fa-fw"></i> {{ lang._('Stop All') }}
                    </button>
                    <button id="btnRestartAll" class="btn btn-xs btn-warning"
                            title="{{ lang._('Restart all running instances') }}">
                        <i class="fa fa-refresh fa-fw"></i> {{ lang._('Restart All') }}
                    </button>
                </div>

                <div style="width: 1px; height: 22px; background: #ddd;"></div>

                <button id="btnTestConnect" class="btn btn-xs btn-default"
                        title="{{ lang._('Test SOCKS connectivity of enabled running instances') }}">
                    <i class="fa fa-plug fa-fw"></i> {{ lang._('Test all IF') }}
                </button>
                <button id="btnTestAutoRoute" class="btn btn-xs btn-default"
                        title="{{ lang._('One-shot Auto route: probe latency and select active tun2socks') }}">
                    <i class="fa fa-random fa-fw"></i> {{ lang._('Test auto route') }}
                </button>
                <span id="testConnectResult" style="font-size: 12px;"></span>
                {{ partial('OPNsense/Xray/partials/help_toggle') }}

            </div>
        </section>
    </div>

    {# ── Instances grid ──────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <table id="grid-instances"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogInstance"
                   data-editAlert="InstanceChangeMessage">
                <thead>
                    <tr>
                        <th data-column-id="uuid"
                            data-type="string"
                            data-identifier="true"
                            data-visible="false">{{ lang._('ID') }}</th>

                        <th data-column-id="enabled"
                            data-width="6em"
                            data-type="string"
                            data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>

                        <th data-column-id="name"
                            data-type="string">
                            <a href="#" class="xray-showhelp" id="help_for_instances"><i class="fa fa-info-circle"></i></a>
                            {{ lang._('Name') }}
                        </th>

                        <th data-column-id="server_address"
                            data-type="string">{{ lang._('Server') }}</th>

                        <th data-column-id="server_port"
                            data-type="string"
                            data-width="6em">{{ lang._('Port') }}</th>

                        <th data-column-id="inst_status"
                            data-formatter="instanceStatus"
                            data-sortable="false"
                            data-width="10em">{{ lang._('Status') }}</th>

                        <th data-column-id="inst_active_tun_v4"
                            data-formatter="instanceActiveTunV4"
                            data-sortable="false"
                            data-width="8em">{{ lang._('Active TUN v4') }}</th>

                        <th data-column-id="inst_active_tun_v6"
                            data-formatter="instanceActiveTunV6"
                            data-sortable="false"
                            data-width="8em">{{ lang._('Active TUN v6') }}</th>

                        <th data-column-id="inst_testresult"
                            data-formatter="instanceTestResult"
                            data-sortable="false"
                            data-width="10em">{{ lang._('Test Result') }}</th>

                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="11em">{{ lang._('') }}</th>
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
                {{ lang._('After saving your changes, please click Apply to activate them.') }}
            </div>
            <div class="hidden" data-for="help_for_instances" style="padding: 8px 0 0;">
                <small class="text-muted">{{ lang._('Each instance runs xray-core and tun2socks. Enable it, then click Apply. Field help is also on the edit dialog (full help).') }}</small>
            </div>
        </section>
    </div>

</div>
