<div class="row xray-bird-toolbar">
    <section class="col-xs-12">
        <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
            <div>
                <span class="xray-badge-bird label label-default">bird: ...</span>
            </div>
            <div style="width: 1px; height: 22px; background: #ddd;"></div>
            <button type="button" class="btn btn-xs btn-default xray-btn-bird-testall"
                    title="{{ lang._('Refresh peer status via birdc show protocols all') }}">
                <i class="fa fa-plug fa-fw"></i> {{ lang._('Test All') }}
            </button>
            <span class="xray-bird-test-result" style="font-size: 12px;"></span>
            {{ partial('OPNsense/Xray/partials/help_toggle') }}
        </div>
    </section>
</div>
