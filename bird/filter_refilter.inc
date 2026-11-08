filter filter_refilter {
    if net = 0.0.0.0/0 then reject;
    ifname = ACTIVE_TUN4_IF;
    accept;
}
