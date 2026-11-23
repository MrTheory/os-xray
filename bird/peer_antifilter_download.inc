protocol bgp antifilter_download {
    local as 65103;
    neighbor 45.154.73.71 as 65432;
    # source address <WAN_IPv4>;
    ipv4 {
        import filter filter_antifilter_download;
        export none;
    };
    multihop;
    hold time 240;
}
