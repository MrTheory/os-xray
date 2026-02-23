<?php

function xray_services()
{
    $services = [];
    $services[] = [
        'description' => 'Xray',
        'configd'     => [
            'restart' => ['xray restart'],
            'start'   => ['xray start'],
            'stop'    => ['xray stop'],
        ],
        'name'    => 'xray',
        'pidfile' => '/var/run/xray_core.pid',
    ];
    return $services;
}

function xray_xmlrpc_sync()
{
    return [];
}
