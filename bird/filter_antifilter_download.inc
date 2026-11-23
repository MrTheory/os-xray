filter filter_antifilter_download {
    if bgp_community ~ community_ANTIFILTER_DOWNLOAD then { 
       if net = 0.0.0.0/0 then reject;
       ifname = ACTIVE_TUN4_IF;
       accept;
    }
    reject; 
}
