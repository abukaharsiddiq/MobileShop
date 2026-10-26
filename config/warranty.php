<?php

return [

    /*
    | Product e warranty (months) na deya thakle product type onujayi default (null = warranty nei).
    | Accessory r alada duration ekhane ba product e.
    */
    'shop_defaults' => [
        'smart_phone'       => null,
        'used_phone'        => null,
        'refurbished_phone' => null,
        'button_phone'      => null,
        'accessory'         => null,
    ],

    // eto din baki thakle "expiring soon" (reminder list)
    'expiring_soon_days' => (int) env('WARRANTY_EXPIRING_SOON_DAYS', 30),

    /*
    | Policy:
    | - exchange: ferot device er warranty batil; notun device er warranty 'new' (puro notun) ba 'remaining'
    |   (ferot device er baki meyad porjonto).
    | - replacement: warranty claim e device bodle dile notun device er warranty 'remaining' ba 'new'.
    */
    'exchange_policy'    => env('WARRANTY_EXCHANGE_POLICY', 'new'),
    'replacement_policy' => env('WARRANTY_REPLACEMENT_POLICY', 'remaining'),

    'claim_reasons' => [
        'no_power'  => 'Dead / no power',
        'display'   => 'Display / touch',
        'battery'   => 'Battery / charging',
        'network'   => 'Network / SIM',
        'camera'    => 'Camera',
        'audio'     => 'Speaker / mic',
        'software'  => 'Software / hanging',
        'physical'  => 'Physical damage',
        'other'     => 'Other',
    ],

];
