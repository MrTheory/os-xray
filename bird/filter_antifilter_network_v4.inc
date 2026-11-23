filter filter_antifilter_network_v4 { 
    if bgp_community ~ community_ANTIFILTER_NETWORK then {
       if net = 0.0.0.0/0 then reject;
       ifname = ACTIVE_TUN4_IF;
       accept;
    }
    reject;
}
