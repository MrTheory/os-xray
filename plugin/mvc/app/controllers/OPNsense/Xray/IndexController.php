<?php

namespace OPNsense\Xray;

/**
 * Renders the main GUI page.
 * Passes both forms to the volt template.
 * Route: /ui/xray/  — redirects to path-based tab URL for menu selection.
 */
class IndexController extends \OPNsense\Base\IndexController
{
    /**
     * Shared page render used by Index and Page controllers.
     */
    protected function renderMainPage(string $tab = 'instances'): void
    {
        try {
            (new BgpCommunity())->seedDefaultCommunitiesIfEmpty();
            (new BgpCommunity())->migrateCommunityFileNames();
            (new BgpCommunity())->ensureDnstapBlockedCommunity();
            (new BgpCommunity())->ensureDnstapDirectCommunity();
            (new BgpCommunity())->ensureDnstapBlockCommunity();
            (new BgpFilter())->seedDefaultFiltersIfEmpty();
            (new BgpFilter())->migrateAcceptFilterNames();
            (new BgpFilter())->ensureDnstapFilters();
            (new BgpPeer())->seedDefaultPeersIfEmpty();
            (new BgpPeer())->migrateAcceptImportNames();
            (new BgpPeer())->ensureDnstapPeerFilters();
        } catch (\Throwable $e) {
            syslog(LOG_ERR, 'os-xray BGP seed: ' . $e->getMessage());
        }
        $this->view->xrayInitialTab = $tab !== '' ? $tab : 'instances';
        $this->view->generalForm  = $this->getForm('general');
        $this->view->instanceForm = $this->getForm('instance');
        $this->view->bgppeerForm      = $this->getForm('bgppeer');
        $this->view->bgpfilterForm    = $this->getForm('bgpfilter');
        $this->view->bgpcommunityForm = $this->getForm('bgpcommunity');
        $this->view->pick('OPNsense/Xray/general');
    }

    public function indexAction()
    {
        // Legacy /ui/xray and /ui/xray/?tab=… → path URL so left menu stays expanded
        $tab = 'instances';
        if ($this->request->hasQuery('tab')) {
            $q = preg_replace('/[^a-z0-9\-]/i', '', (string)$this->request->getQuery('tab'));
            if ($q !== '') {
                $tab = $q;
            }
        }
        $this->response->redirect('/ui/xray/page/show/' . rawurlencode($tab), true);
    }
}
