<!-- LOGS -->
<div id="logs" class="tab-pane fade in">

    {# ── Toolbar ─────────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">

                {# Log type sub-tabs #}
                <ul class="nav nav-pills nav-sm" id="logSubTabs" style="margin: 0;">
                    <li class="active">
                        <a data-toggle="tab" href="#logBoot">
                            <i class="fa fa-terminal fa-fw"></i> {{ lang._('Boot Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logAutoroute">
                            <i class="fa fa-random fa-fw"></i> {{ lang._('Autoroute Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logCore">
                            <i class="fa fa-file-text-o fa-fw"></i> {{ lang._('Core Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logBird">
                            <i class="fa fa-sitemap fa-fw"></i> {{ lang._('Bird Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logDnstap">
                            <i class="fa fa-exchange fa-fw"></i> {{ lang._('DNStap Log') }}
                        </a>
                    </li>
                </ul>
                <a href="#" class="xray-showhelp" id="help_for_logs"><i class="fa fa-info-circle"></i></a>
                {{ partial('OPNsense/Xray/partials/help_toggle') }}
                <div class="hidden" data-for="help_for_logs" style="flex-basis: 100%;">
                    <small class="text-muted">{{ lang._('Boot, Autoroute, Core, Bird and DNStap logs. Refresh reloads the last lines from the corresponding file.') }}</small>
                </div>

            </div>
        </section>
    </div>

    {# ── Log panes ───────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div class="tab-content" style="padding: 0 15px 15px;">

                <div id="logBoot" class="tab-pane fade in active" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logBootRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/xray-boot.log — last 300 lines (boot, Test all IF, IF health cron)') }}
                        </span>
                    </div>
                    <pre id="logBootContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Switch to this tab to load log.') }}</pre>
                </div>

                <div id="logAutoroute" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logAutorouteRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/xray-autoroute.log — last 300 lines (Test auto route, cron)') }}
                        </span>
                    </div>
                    <pre id="logAutorouteContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Click "Autoroute Log" to load.') }}</pre>
                </div>

                <div id="logCore" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logCoreRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <div class="input-group input-group-sm" style="min-width: 200px; width: auto;">
                            <span class="input-group-addon">
                                <i class="fa fa-server fa-fw"></i>
                            </span>
                            <select id="logInstanceSelect" class="form-control">
                            </select>
                        </div>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/xray-core-{uuid}.log — last 200 lines') }}
                        </span>
                    </div>
                    <pre id="logCoreContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Click "Core Log" to load.') }}</pre>
                </div>

                <div id="logBird" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logBirdRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <div class="input-group input-group-sm" style="min-width: 160px; width: auto;">
                            <span class="input-group-addon">
                                {{ lang._('syslog') }}
                            </span>
                            <select id="logBirdLevelSelect" class="form-control">
                                <option value="debug">debug</option>
                                <option value="trace">trace</option>
                                <option value="info">info</option>
                                <option value="remote">remote</option>
                                <option value="warning" selected>warning</option>
                                <option value="error">error</option>
                                <option value="auth">auth</option>
                                <option value="fatal">fatal</option>
                                <option value="bug">bug</option>
                                <option value="all">all</option>
                            </select>
                        </div>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/bird/bird.log — last 200 lines') }}
                        </span>
                    </div>
                    <pre id="logBirdContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Click "Bird Log" to load.') }}</pre>
                </div>

                <div id="logDnstap" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logDnstapRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/dnstap-bgp/dnstap-bgp.log — last 200 lines') }}
                        </span>
                    </div>
                    <pre id="logDnstapContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Click "DNStap Log" to load.') }}</pre>
                </div>

            </div>
        </section>
    </div>

</div>
