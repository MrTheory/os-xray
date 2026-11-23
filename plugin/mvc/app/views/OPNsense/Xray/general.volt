<style>
    /*
     * Windows Chrome has no country-flag glyphs in Segoe UI Emoji (shows NL / blank).
     * TwemojiCountryFlags covers only regional-indicator / flag tag ranges.
     * Applied to all Xray tabs + dialogs where instance names appear
     * (Instances grid, Domains Check → Instance, Diagnostics/Log selects, Edit Instance).
     */
    @font-face {
        font-family: "Twemoji Country Flags";
        unicode-range: U+1F1E6-1F1FF, U+1F3F4, U+E0062-E007F;
        src: url("/ui/fonts/xray/TwemojiCountryFlags.woff2") format("woff2");
        font-display: swap;
    }
    .tab-content.content-box,
    .tab-content.content-box td,
    .tab-content.content-box th,
    .tab-content.content-box input,
    .tab-content.content-box select,
    .tab-content.content-box option,
    .tab-content.content-box textarea,
    .tab-content.content-box label,
    .tab-content.content-box .bootgrid-header,
    .tab-content.content-box .bootgrid-footer,
    #domainsCheckTable,
    #domainsCheckTable td,
    #diagInstanceSelect,
    #diagInstanceSelect option,
    #logInstanceSelect,
    #logInstanceSelect option,
    #DialogInstance,
    #DialogInstance input,
    #DialogInstance select,
    #DialogInstance option,
    #DialogInstance textarea,
    #DialogBgpPeer,
    #DialogBgpPeer input,
    #DialogBgpPeer select,
    #DialogBgpPeer option,
    #DialogBgpPeer textarea,
    #DialogBgpFilter,
    #DialogBgpFilter input,
    #DialogBgpFilter select,
    #DialogBgpFilter option,
    #DialogBgpFilter textarea,
    #DialogBgpCommunity,
    #DialogBgpCommunity input,
    #DialogBgpCommunity select,
    #DialogBgpCommunity option,
    #DialogBgpCommunity textarea,
    #DialogInstance input[id="instance.name"],
    #grid-instances,
    #grid-instances td {
        font-family: "Twemoji Country Flags", system-ui, -apple-system, "Segoe UI", Roboto,
            "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji",
            "Noto Color Emoji", emoji;
    }
    #DialogBgpFilter tr.xray-filter-block-start > td {
        border-top: 1px solid #bbb;
        border-left: 1px solid #bbb;
        border-right: 1px solid #bbb;
        padding-top: 8px;
    }
    #DialogBgpFilter tr.xray-filter-block-end > td {
        border-bottom: 1px solid #bbb;
        border-left: 1px solid #bbb;
        border-right: 1px solid #bbb;
        padding-bottom: 8px;
    }
    #DialogBgpFilter tr.xray-filter-block-actions > td {
        padding-top: 6px;
        padding-bottom: 4px;
    }
    /* Geodat Category modals: center saved action in the select field */
    #staticGeodatModal select.static-geodat-action,
    #dnstapGeodatModal select.dnstap-geodat-action {
        text-align: center;
        text-align-last: center;
        height: 30px;
        line-height: 28px;
        padding-top: 0;
        padding-bottom: 0;
    }
    #staticGeodatModal select.static-geodat-action option,
    #dnstapGeodatModal select.dnstap-geodat-action option {
        text-align: center;
    }
</style>
{{ partial('OPNsense/Xray/partials/scripts') }}

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#instances">{{ lang._('Instances') }}</a></li>
    <li class="dropdown">
        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
            {{ lang._('Routing') }} <span class="caret"></span>
        </a>
        <ul class="dropdown-menu">
            <li><a data-toggle="tab" href="#routing-peers">{{ lang._('BGP peers') }}</a></li>
            <li><a data-toggle="tab" href="#routing-filters">{{ lang._('BGP filter') }}</a></li>
            <li><a data-toggle="tab" href="#routing-communities">{{ lang._('BGP community') }}</a></li>
            <li><a data-toggle="tab" href="#routing-static">{{ lang._('Static') }}</a></li>
        </ul>
    </li>
    <li><a data-toggle="tab" href="#dnstap">{{ lang._('DNStap') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#domains">{{ lang._('Extra Domain IP') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ lang._('Log') }}</a></li>
</ul>

<div class="tab-content content-box">
    {{ partial('OPNsense/Xray/partials/tab_instances') }}
    {{ partial('OPNsense/Xray/partials/tab_routing') }}
    {{ partial('OPNsense/Xray/partials/tab_static') }}
    {{ partial('OPNsense/Xray/partials/tab_dnstap') }}

    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", {'fields': generalForm, 'id': 'frm_general_settings'}) }}
    </div>

    {{ partial('OPNsense/Xray/partials/tab_domains') }}
    {{ partial('OPNsense/Xray/partials/tab_diagnostics') }}
    {{ partial('OPNsense/Xray/partials/tab_logs') }}
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/xray/service/reconfigure'}) }}

{{ partial("layout_partials/base_dialog", ['fields': instanceForm, 'id': 'DialogInstance', 'label': lang._('Edit Instance')]) }}
{{ partial("layout_partials/base_dialog", ['fields': bgppeerForm, 'id': 'DialogBgpPeer', 'label': lang._('Edit BGP Peer')]) }}
{{ partial("layout_partials/base_dialog", ['fields': bgpfilterForm, 'id': 'DialogBgpFilter', 'label': lang._('Edit BGP Filter')]) }}
{{ partial("layout_partials/base_dialog", ['fields': bgpcommunityForm, 'id': 'DialogBgpCommunity', 'label': lang._('Edit BGP Community')]) }}

{{ partial('OPNsense/Xray/partials/modal_debug') }}
