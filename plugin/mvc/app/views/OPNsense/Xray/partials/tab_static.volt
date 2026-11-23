<div id="routing-static" class="tab-pane fade">
    <style>
        #routing-static .static-row-edit {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        #routing-static .static-row-edit .form-control {
            flex: 1;
            min-width: 0;
            height: 28px;
            padding: 4px 8px;
            font-size: 13px;
        }
        #routing-static .static-row-edit .static-url-fetch {
            flex-shrink: 0;
            min-width: 28px;
            padding: 2px 6px;
        }
        #routing-static .static-row-edit .static-url-enable {
            flex-shrink: 0;
            margin: 0;
            font-weight: normal;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
        }
        #routing-static .xray-block {
            margin-bottom: 8px;
        }
    </style>

    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                {{ partial('OPNsense/Xray/partials/help_toggle') }}
            </div>
        </section>
    </div>

    <div class="row">
        <section class="col-xs-12">
            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_static_enabled">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Enable static route') }}
                                <input type="checkbox" id="staticEnabled"
                                       style="margin-left: 8px; vertical-align: middle;">
                            </th>
                        </tr>
                    </thead>
                </table>
                <div class="hidden" data-for="help_for_static_enabled" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('When enabled, BIRD loads static_v4/static_v6 from *_apply.inc. When disabled, those protocols are removed from BIRD.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_static_proxy_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('PROXY IP list URLs') }}
                                <span id="staticProxyCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="staticProxyUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary static-url-add"
                                        data-list="staticProxyUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default static-url-remove"
                                        data-list="staticProxyUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_static_proxy_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) PROXY IP/CIDR lists. ✔ downloads and caches this URL. Enable includes cached data in BIRD. Apply saves URLs and rebuilds from enabled caches only.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_static_direct_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('DIRECT IP list URLs') }}
                                <span id="staticDirectCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="staticDirectUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary static-url-add"
                                        data-list="staticDirectUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default static-url-remove"
                                        data-list="staticDirectUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_static_direct_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) DIRECT IP/CIDR lists. ✔ downloads and caches; Enable applies cache to BIRD (via IPv4_GATEWAY / IPv6_GATEWAY).') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_static_block_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('BLOCK IP list URLs') }}
                                <span id="staticBlockCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="staticBlockUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary static-url-add"
                                        data-list="staticBlockUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default static-url-remove"
                                        data-list="staticBlockUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_static_block_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) BLOCK IP/CIDR lists. ✔ downloads and caches; Enable applies cache to BIRD (blackhole).') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_static_geodat_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Geodat IP list URLs') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody id="staticGeodatUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary static-url-add"
                                        data-list="staticGeodatUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default static-url-remove"
                                        data-list="staticGeodatUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="staticGeodatCategory"
                                        title="{{ lang._('Category') }}">
                                    {{ lang._('Category') }}
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_static_geodat_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) geoip.dat. ✔ caches the file; Enable + Category assignments feed *_geodat.inc. Daily refresh updates only enabled URLs.') }}</small>
                </div>
            </div>
        </section>
    </div>

    <div class="modal fade" id="staticGeodatModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">{{ lang._('Geodat categories') }}</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted" id="staticGeodatModalMsg"></p>
                    <div class="form-group" style="margin-bottom: 8px;">
                        <label for="staticGeodatUrlPick">{{ lang._('Geodat URL') }}</label>
                        <select id="staticGeodatUrlPick" class="form-control input-sm"></select>
                    </div>
                    <div class="form-group" style="margin-bottom: 8px;">
                        <input type="text" class="form-control input-sm" id="staticGeodatFilter"
                               placeholder="{{ lang._('Filter categories') }}"/>
                    </div>
                    <table class="table table-condensed table-hover">
                        <thead>
                            <tr>
                                <th>{{ lang._('Category') }}</th>
                                <th>{{ lang._('Count') }}</th>
                                <th>{{ lang._('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody id="staticGeodatCats"></tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
                    <button type="button" class="btn btn-primary static-geodat-save">{{ lang._('Save') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
