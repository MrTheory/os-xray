protocol bgp refilter {
    local as 65103;
    neighbor 165.22.127.207 as 65412;
    # source address <WAN_IPv4>;
    ipv4 {
        import filter filter_refilter;
        export none;
    };
    multihop;
    hold time 240;
}
