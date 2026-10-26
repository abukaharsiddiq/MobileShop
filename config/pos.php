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
        // customer er store credit (sale return theke) - notun taka na, tai cash/bank report e ashe na
        'Store Credit' => 'credit',
    ],

    // ei dhoron er payment e transaction/cheque reference lage (cash e na)
    'reference_required' => ['bank', 'mobile'],

    /*
    | Sales return approval: false hole 'create return' thaka user sathe sathe ferot nite pare (ager moto).
    | true hole 'approve return' chara user er ferot "pending" thake; approver inspection dekhe approve kore.
    */
    'return_requires_approval' => (bool) env('SALE_RETURN_REQUIRES_APPROVAL', false),

];
