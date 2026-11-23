protocol bgp antifilter_network {
    local as 65103;
    neighbor 45.148.244.55 as 65444;
    # source address <WAN_IPv4>;
    ipv4 {
        import filter filter_antifilter_network_v4;
        export none;
    };
    ipv6 {
        import filter filter_antifilter_network_v6;
        export none;
    };
    multihop;
    hold time 240;
}
