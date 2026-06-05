<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SIM inventory alerts
    |--------------------------------------------------------------------------
    |
    | low_stock_threshold: alert when available (unassigned) numbers <= this value
    | critical_stock_threshold: urgent alert when available <= this value
    |
    */
    'inventory' => [
        'low_stock_threshold' => (int) env('INVENTORY_LOW_STOCK_THRESHOLD', 5),
        'critical_stock_threshold' => (int) env('INVENTORY_CRITICAL_STOCK_THRESHOLD', 2),
        'default_network_id' => (int) env('INVENTORY_DEFAULT_NETWORK_ID', 1),
    ],

];
