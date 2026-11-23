<div id="dnstap" class="tab-pane fade">
    <style>
        #dnstap td.dnstap-k {
            width: 22%;
            white-space: nowrap;
        }
        #dnstap .dnstap-row-edit {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        #dnstap .dnstap-row-edit .form-control,
        #dnstap #dnstapConfKv .form-control {
            flex: 1;
            min-width: 0;
            height: 28px;
            padding: 4px 8px;
            font-size: 13px;
        }
        #dnstap .dnstap-row-edit .btn {
            flex-shrink: 0;
        }
        #dnstap .dnstap-row-edit .dnstap-url-fetch {
            flex-shrink: 0;
            min-width: 28px;
            padding: 2px 6px;
        }
        #dnstap .dnstap-row-edit .dnstap-url-enable {
            flex-shrink: 0;
            margin: 0;
            font-weight: normal;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
        }
        #dnstap .xray-block {
            margin-bottom: 8px;
        }
    </style>

    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                <div>
                    <span class="xray-badge-dnstap label label-default">dnstap_bgp: ...</span>
                </div>
                <div style="width: 1px; height: 22px; background: #ddd;"></div>
                <div style="display: flex; gap: 4px;">
                    <button type="button" class="btn btn-xs btn-success" id="dnstapStart"
                            title="{{ lang._('Start dnstap-bgp') }}">
                        <i class="fa fa-play fa-fw"></i> {{ lang._('Start') }}
                    </button>
                    <button type="button" class="btn btn-xs btn-danger" id="dnstapStop"
                            title="{{ lang._('Stop dnstap-bgp') }}">
                        <i class="fa fa-stop fa-fw"></i> {{ lang._('Stop') }}
                    </button>
                </div>
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
                                <a href="#" class="xray-showhelp" id="help_for_dnstap_config">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Config') }}
                            </th>
                            <th>{{ lang._('Value') }}</th>
                        </tr>
                    </thead>
                    <tbody id="dnstapConfKv"></tbody>
                </table>
                <div class="hidden" data-for="help_for_dnstap_config" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('If a name is in both lists, PROXY wins. Communities are applied on SIGHUP.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_dnstap_proxy_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('PROXY domain list URLs') }}
                                <span id="dnstapProxyCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="dnstapProxyUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary dnstap-url-add"
                                        data-list="dnstapProxyUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default dnstap-url-remove"
                                        data-list="dnstapProxyUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_dnstap_proxy_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) PROXY domain lists. ✔ downloads and caches this URL. Enable includes cached data in the summarized file. Apply saves URLs and rebuilds from enabled caches.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_dnstap_direct_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('DIRECT domain list URLs') }}
                                <span id="dnstapDirectCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="dnstapDirectUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary dnstap-url-add"
                                        data-list="dnstapDirectUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default dnstap-url-remove"
                                        data-list="dnstapDirectUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_dnstap_direct_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) DIRECT domain lists. ✔ caches; Enable applies cache. Names also in BLOCK or PROXY are dropped.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_dnstap_block_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('BLOCK domain list URLs') }}
                                <span id="dnstapBlockCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="dnstapBlockUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary dnstap-url-add"
                                        data-list="dnstapBlockUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default dnstap-url-remove"
                                        data-list="dnstapBlockUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_dnstap_block_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) BLOCK domain lists. ✔ caches; Enable applies cache. If a name is in several lists, BLOCK wins over PROXY and DIRECT.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_dnstap_geodat_urls">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Geodat domain list URLs') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody id="dnstapGeodatUrlsKv" data-row-key="url"></tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary dnstap-url-add"
                                        data-list="dnstapGeodatUrlsKv" title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default dnstap-url-remove"
                                        data-list="dnstapGeodatUrlsKv" title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default dnstap-geodat-category">
                                    {{ lang._('Category') }}
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_dnstap_geodat_urls" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('HTTP(S) geosite.dat. ✔ caches the file; Enable + Category assignments feed summaries. Daily refresh updates only enabled URLs.') }}</small>
                </div>
            </div>
        </section>
    </div>

    <div class="modal fade" id="dnstapGeodatModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">{{ lang._('Geodat categories') }}</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted" id="dnstapGeodatStatus" style="margin-bottom:8px;"></p>
                    <select class="form-control" id="dnstapGeodatUrlPick" style="margin-bottom:8px;"></select>
                    <input type="text" class="form-control" id="dnstapGeodatFilter"
                           placeholder="{{ lang._('Filter') }}" style="margin-bottom:8px;">
                    <div style="max-height:360px; overflow:auto;">
                        <table class="table table-condensed table-hover table-striped">
                            <thead>
                                <tr>
                                    <th>{{ lang._('Category') }}</th>
                                    <th style="width:90px;">{{ lang._('Domains') }}</th>
                                    <th style="width:140px;">{{ lang._('List') }}</th>
                                </tr>
                            </thead>
                            <tbody id="dnstapGeodatCats"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
                    <button type="button" class="btn btn-primary" id="dnstapGeodatSave">{{ lang._('Save') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
