<?php

return [

    /*
    | POS e je payment method neya jay ar tar dhoron (cash / bank / mobile banking).
    | Notun method jog korte ekhane likhun; form, validation ar report ei list theke.
    */
    'payment_methods' => [
        'Cash'   => 'cash',
        'Bkash'  => 'mobile',
        'Nagad'  => 'mobile',
        'Rocket' => 'mobile',
        'Upay'   => 'mobile',
        'Bank'   => 'bank',
        'Card'   => 'bank',
        'Cheque' => 'bank',
    ],

    // ei dhoron er payment e transaction/cheque reference lage (cash e na)
    'reference_required' => ['bank', 'mobile'],

];
