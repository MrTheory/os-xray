<?php

namespace OPNsense\Xray;

/**
 * Path-based tabs for left-menu selection.
 * OPNsense strips ?query before matching Menu.xml, so /ui/xray/?tab=… never
 * marks VPN→Xray selected and the sidebar collapses. Paths stay in REQUEST_URI.
 *
 * Route: /ui/xray/page/show/<tab>
 */
class PageController extends IndexController
{
    public function showAction($tab = 'instances')
    {
        $tab = preg_replace('/[^a-z0-9\-]/i', '', (string)$tab);
        if ($tab === '') {
            $tab = 'instances';
        }
        $this->renderMainPage($tab);
    }
}
