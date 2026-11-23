<div id="domains" class="tab-pane fade">
    <style>
        #domains .dnstap-row-edit {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        #domains .dnstap-row-edit .form-control {
            flex: 1;
            min-width: 0;
            height: 28px;
            padding: 4px 8px;
            font-size: 13px;
        }
        #domains .dnstap-row-edit .btn {
            flex-shrink: 0;
        }
        #domains .dnstap-row-edit .domains-extra-save {
            flex-shrink: 0;
            min-width: 28px;
            padding: 2px 6px;
        }
        #domains .xray-block {
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
                                <a href="#" class="xray-showhelp" id="help_for_domains_check">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Check domain and IP route') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="dnstap-row-edit">
                                    <input type="text" class="form-control" id="domainsCheckInput"
                                           placeholder="{{ lang._('example.com or 1.1.1.1') }}"/>
                                    <button type="button" class="btn btn-xs btn-primary" id="domainsCheckBtn"
                                            title="{{ lang._('Check') }}">
                                        <span class="fa fa-fw fa-search"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="hidden" data-for="help_for_domains_check" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('Enter a domain or an IPv4/IPv6 address. Domains are resolved; for each IP birdcl show route for IP all is run. Class is the dnstap BGP community (PROXY=777→TUN, DIRECT=555→WAN, BLOCK=888→Null). List is the class from proxy/direct/block.txt. Active is the BIRD * route. If Network not found: peer N/A, instance N/A, interface WAN4/WAN6, Active yes.') }}</small>
                </div>
                <table class="table table-condensed table-hover table-striped" id="domainsCheckTable" style="display: none; margin-top: 8px;">
                    <thead>
                        <tr>
                            <th>{{ lang._('Domain') }}</th>
                            <th>{{ lang._('IP address') }}</th>
                            <th>{{ lang._('BGP peer') }}</th>
                            <th>{{ lang._('Class') }}</th>
                            <th>{{ lang._('List') }}</th>
                            <th>{{ lang._('Instance') }}</th>
                            <th>{{ lang._('Interface') }}</th>
                            <th>{{ lang._('Active') }}</th>
                        </tr>
                    </thead>
                    <tbody id="domainsCheckRows"></tbody>
                </table>
                <p class="text-muted" id="domainsCheckMsg" style="margin: 8px 0 0; font-size: 12px; display: none;"></p>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_domains_proxy">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Extra PROXY domains') }}
                                <span id="domainsProxyCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="dnstap-row-edit">
                                    <input type="text" class="form-control" id="domainsProxyInput"
                                           placeholder="{{ lang._('example.com') }}"/>
                                    <button type="button" class="btn btn-xs btn-default domains-extra-save"
                                            data-extra="domain" data-kind="proxy"
                                            title="{{ lang._('Save') }}">✔</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary" id="domainsProxyAdd"
                                        title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsProxyRemove"
                                        title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsProxySearch"
                                        title="{{ lang._('Show PROXY domains') }}">
                                    <span class="fa fa-fw fa-search"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_domains_proxy" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('Add a domain to the extra PROXY list. Remove deletes the domain in the field. The magnifying glass shows all extra PROXY names.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_domains_direct">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Extra DIRECT domains') }}
                                <span id="domainsDirectCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="dnstap-row-edit">
                                    <input type="text" class="form-control" id="domainsDirectInput"
                                           placeholder="{{ lang._('example.com') }}"/>
                                    <button type="button" class="btn btn-xs btn-default domains-extra-save"
                                            data-extra="domain" data-kind="direct"
                                            title="{{ lang._('Save') }}">✔</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary" id="domainsDirectAdd"
                                        title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsDirectRemove"
                                        title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsDirectSearch"
                                        title="{{ lang._('Show DIRECT domains') }}">
                                    <span class="fa fa-fw fa-search"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_domains_direct" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('Add a domain to the extra DIRECT list. Names also present in BLOCK or PROXY are dropped on Apply.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_domains_block">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Extra BLOCK domains') }}
                                <span id="domainsBlockCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="dnstap-row-edit">
                                    <input type="text" class="form-control" id="domainsBlockInput"
                                           placeholder="{{ lang._('example.com') }}"/>
                                    <button type="button" class="btn btn-xs btn-default domains-extra-save"
                                            data-extra="domain" data-kind="block"
                                            title="{{ lang._('Save') }}">✔</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary" id="domainsBlockAdd"
                                        title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsBlockRemove"
                                        title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsBlockSearch"
                                        title="{{ lang._('Show BLOCK domains') }}">
                                    <span class="fa fa-fw fa-search"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_domains_block" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('Add a domain to the extra BLOCK list. If a name is also in PROXY or DIRECT, BLOCK wins.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_domains_ip_proxy">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Extra PROXY IP') }}
                                <span id="domainsIpProxyCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="dnstap-row-edit">
                                    <input type="text" class="form-control" id="domainsIpProxyInput"
                                           placeholder="{{ lang._('1.2.3.4 or 2001:db8::/32') }}"/>
                                    <button type="button" class="btn btn-xs btn-default domains-extra-save"
                                            data-extra="ip" data-kind="proxy"
                                            title="{{ lang._('Save') }}">✔</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary" id="domainsIpProxyAdd"
                                        title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsIpProxyRemove"
                                        title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsIpProxySearch"
                                        title="{{ lang._('Show PROXY IPs') }}">
                                    <span class="fa fa-fw fa-search"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_domains_ip_proxy" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('Add an IPv4/IPv6 address or subnet to the extra PROXY list. Save only stores it in the list. Apply rebuilds BIRD static routes the same way as the Static tab. Routes are installed only while Enable static route is on. BLOCK overrides PROXY, PROXY overrides DIRECT.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_domains_ip_direct">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Extra DIRECT IP') }}
                                <span id="domainsIpDirectCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="dnstap-row-edit">
                                    <input type="text" class="form-control" id="domainsIpDirectInput"
                                           placeholder="{{ lang._('1.2.3.4 or 2001:db8::/32') }}"/>
                                    <button type="button" class="btn btn-xs btn-default domains-extra-save"
                                            data-extra="ip" data-kind="direct"
                                            title="{{ lang._('Save') }}">✔</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary" id="domainsIpDirectAdd"
                                        title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsIpDirectRemove"
                                        title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsIpDirectSearch"
                                        title="{{ lang._('Show DIRECT IPs') }}">
                                    <span class="fa fa-fw fa-search"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_domains_ip_direct" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('Add an IPv4/IPv6 address or subnet to the extra DIRECT list. Save only stores it in the list. Apply rebuilds BIRD static routes. A prefix also listed as BLOCK or PROXY is not installed as DIRECT.') }}</small>
                </div>
            </div>

            <div class="xray-block">
                <table class="table table-condensed table-hover table-striped">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="xray-showhelp" id="help_for_domains_ip_block">
                                    <i class="fa fa-info-circle"></i>
                                </a>
                                {{ lang._('Extra BLOCK IP') }}
                                <span id="domainsIpBlockCount" class="label label-info" style="font-size:11px; margin-left:8px; display:none;"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="dnstap-row-edit">
                                    <input type="text" class="form-control" id="domainsIpBlockInput"
                                           placeholder="{{ lang._('1.2.3.4 or 2001:db8::/32') }}"/>
                                    <button type="button" class="btn btn-xs btn-default domains-extra-save"
                                            data-extra="ip" data-kind="block"
                                            title="{{ lang._('Save') }}">✔</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-xs btn-primary" id="domainsIpBlockAdd"
                                        title="{{ lang._('Add') }}">
                                    <span class="fa fa-fw fa-plus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsIpBlockRemove"
                                        title="{{ lang._('Remove') }}">
                                    <span class="fa fa-fw fa-minus"></span>
                                </button>
                                <button type="button" class="btn btn-xs btn-default" id="domainsIpBlockSearch"
                                        title="{{ lang._('Show BLOCK IPs') }}">
                                    <span class="fa fa-fw fa-search"></span>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="hidden" data-for="help_for_domains_ip_block" style="padding: 8px 0 0;">
                    <small class="text-muted">{{ lang._('Add an IPv4/IPv6 address or subnet to the extra BLOCK list. Save only stores it in the list. Apply rebuilds BIRD static routes (blackhole). BLOCK overrides PROXY and DIRECT.') }}</small>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="modal fade" id="domainsListModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title" id="domainsListModalTitle">{{ lang._('Extra PROXY domains') }}</h4>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control input-sm" id="domainsListFilter"
                       placeholder="{{ lang._('Filter') }}" style="margin-bottom: 8px;"/>
                <div id="domainsListBody"
                     style="max-height: 55vh; overflow-y: auto;">
                    <table class="table table-condensed table-striped table-hover" style="margin-bottom: 0;">
                        <tbody id="domainsListRows"></tbody>
                    </table>
                </div>
                <p class="text-muted" style="margin: 8px 0 0; font-size: 12px;" id="domainsListCount"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>
