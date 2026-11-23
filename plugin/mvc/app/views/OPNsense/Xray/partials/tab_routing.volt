<div id="routing-peers" class="tab-pane fade in">
    {{ partial('OPNsense/Xray/partials/toolbar_bird') }}
    <div class="row">
        <section class="col-xs-12">
            <table id="grid-bgppeers"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogBgpPeer"
                   data-editAlert="BgpPeerChangeMessage">
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
                            <a href="#" class="xray-showhelp" id="help_for_bgp_peers"><i class="fa fa-info-circle"></i></a>
                            {{ lang._('Name') }}
                        </th>
                        <th data-column-id="neighbor"
                            data-type="string">{{ lang._('Neighbor') }}</th>
                        <th data-column-id="neighbor_as"
                            data-type="string"
                            data-width="8em">{{ lang._('Neighbor AS') }}</th>
                        <th data-column-id="local_as"
                            data-type="string"
                            data-width="7em">{{ lang._('Local AS') }}</th>
                        <th data-column-id="prefixes4"
                            data-width="6em"
                            data-type="string"
                            data-formatter="peerPrefixes4"
                            data-sortable="false">{{ lang._('IPv4') }}</th>
                        <th data-column-id="prefixes6"
                            data-width="6em"
                            data-type="string"
                            data-formatter="peerPrefixes6"
                            data-sortable="false">{{ lang._('IPv6') }}</th>
                        <th data-column-id="ipv4_route_int"
                            data-type="string"
                            data-width="14em">{{ lang._('IPv4 route int') }}</th>
                        <th data-column-id="ipv6_route_int"
                            data-type="string"
                            data-width="14em">{{ lang._('IPv6 route int') }}</th>
                        <th data-column-id="peer_status"
                            data-formatter="peerStatus"
                            data-sortable="false"
                            data-width="16em">{{ lang._('Status') }}</th>
                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="7em">{{ lang._('') }}</th>
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
            <div id="BgpPeerChangeMessage" class="alert alert-info" style="display: none;" role="alert">
                {{ lang._('After saving your changes, please click Apply to activate them.') }}
            </div>
            <div class="hidden" data-for="help_for_bgp_peers" style="padding: 8px 0 0;">
                <small class="text-muted">{{ lang._('Each enabled peer is written to /usr/local/etc/bird and included from bgp.conf. Assign an import filter per address family in the peer dialog.') }}</small>
            </div>
        </section>
    </div>
</div>

<div id="routing-filters" class="tab-pane fade">
    {{ partial('OPNsense/Xray/partials/toolbar_bird') }}
    <div class="row">
        <section class="col-xs-12">
            <table id="grid-bgpfilters"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogBgpFilter"
                   data-editAlert="BgpFilterChangeMessage">
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
                            <a href="#" class="xray-showhelp" id="help_for_bgp_filters"><i class="fa fa-info-circle"></i></a>
                            {{ lang._('Name') }}
                        </th>
                        <th data-column-id="community"
                            data-type="string">{{ lang._('Community') }}</th>
                        <th data-column-id="family"
                            data-width="6em"
                            data-type="string">{{ lang._('Family') }}</th>
                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="7em">{{ lang._('') }}</th>
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
            <div id="BgpFilterChangeMessage" class="alert alert-info" style="display: none;" role="alert">
                {{ lang._('After saving your changes, please click Apply to activate them.') }}
            </div>
            <div class="hidden" data-for="help_for_bgp_filters" style="padding: 8px 0 0;">
                <small class="text-muted">{{ lang._('Each enabled filter is written to /usr/local/etc/bird/filter_NAME.inc and included from filters.inc. Assign a community in the filter dialog, then select the filter on a peer.') }}</small>
            </div>
        </section>
    </div>
</div>

<div id="routing-communities" class="tab-pane fade">
    {{ partial('OPNsense/Xray/partials/toolbar_bird') }}
    <div class="row">
        <section class="col-xs-12">
            <table id="grid-bgpcommunities"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogBgpCommunity"
                   data-editAlert="BgpCommunityChangeMessage">
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
                            <a href="#" class="xray-showhelp" id="help_for_bgp_communities"><i class="fa fa-info-circle"></i></a>
                            {{ lang._('Name') }}
                        </th>
                        <th data-column-id="communities"
                            data-type="string">{{ lang._('Communities') }}</th>
                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="7em">{{ lang._('') }}</th>
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
            <div id="BgpCommunityChangeMessage" class="alert alert-info" style="display: none;" role="alert">
                {{ lang._('After saving your changes, please click Apply to activate them.') }}
            </div>
            <div class="hidden" data-for="help_for_bgp_communities" style="padding: 8px 0 0;">
                <small class="text-muted">{{ lang._('Each enabled community is written as /usr/local/etc/bird/NAME.inc (BIRD define NAME). Select it on a BGP filter.') }}</small>
            </div>
        </section>
    </div>
</div>
