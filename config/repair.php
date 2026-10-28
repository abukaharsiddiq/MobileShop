<?php

return [

    // job card e customer er sathe ki ki jama dilo
    'accessories' => [
        'sim'      => 'SIM card',
        'sd_card'  => 'Memory card',
        'cover'    => 'Cover / case',
        'charger'  => 'Charger',
        'cable'    => 'Cable',
        'box'      => 'Box',
        'battery'  => 'Battery (removable)',
    ],

    // delivery te service warranty (din) - form er option; default
    'warranty_options' => [0, 7, 15, 30, 60, 90],
    'default_warranty_days' => (int) env('REPAIR_WARRANTY_DAYS', 30),

    // QC: je technician kaj korlo shei QC pass korte parbe na (admin chara)
    'separate_qc' => (bool) env('REPAIR_SEPARATE_QC', true),

];
