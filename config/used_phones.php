<?php

return [

    // Kar kach theke kena (walk-in = dokane eshe bikri kora manush)
    'sources' => [
        'walk_in'  => 'Walk-in seller',
        'customer' => 'Existing customer',
        'supplier' => 'Supplier / dealer',
    ],

    // Walk-in seller er NID/passport number lagbe (churi phone theke bachte)
    'require_seller_document' => (bool) env('USED_PHONE_REQUIRE_SELLER_DOCUMENT', true),

    'grades' => [
        'A' => 'A - Like new',
        'B' => 'B - Good (light scratches)',
        'C' => 'C - Fair (visible wear)',
        'D' => 'D - Poor / needs repair',
    ],

    // eto % er niche battery health hole list/page e shotorkota
    'battery_warning_below' => 80,

    'accessories' => [
        'box'           => 'Box',
        'charger'       => 'Charger',
        'cable'         => 'Cable',
        'earphone'      => 'Earphone',
        'cover'         => 'Cover / case',
        'receipt'       => 'Original receipt',
        'warranty_card' => 'Warranty card',
        'sim_tool'      => 'SIM tool',
    ],

    // inspection: protiti item ok | fault | na
    'checklist' => [
        'display'    => 'Display',
        'touch'      => 'Touch',
        'camera'     => 'Cameras',
        'speaker'    => 'Speaker',
        'microphone' => 'Microphone',
        'charging'   => 'Charging port',
        'buttons'    => 'Buttons',
        'biometric'  => 'Fingerprint / Face ID',
        'network'    => 'Network / SIM',
        'wifi_bt'    => 'Wi-Fi / Bluetooth',
        'body'       => 'Body / frame',
    ],

    // resale warranty (dokaner, months) - form er option
    'warranty_options' => [0, 1, 2, 3, 6],

];
