<?php

return [

    /*
    | true: cash (drawer) e taka neya/deya age cashier er open cash session lage (POS, collection, expense, refund ...).
    | false: session chara cash posting hoy (ager moto), kintu session thakle sob niyom chole
    | (bondho session e notun cash na, purono open session age close).
    */
    'require_open_session' => (bool) env('CASH_SESSION_REQUIRED', false),

    // gonar jonno note/poisha (taka)
    'denominations' => [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1],

];
