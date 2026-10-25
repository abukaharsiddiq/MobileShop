<?php

return [

    /*
    | Low stock: variant er nijer threshold, na thakle product er, na thakle ei default.
    | Quantity eitar soman ba kom hole "Low Stock" (0 hole "Out of Stock").
    */
    'low_stock_threshold' => (int) env('LOW_STOCK_THRESHOLD', 5),

    // product unit (form er select ar validation)
    'units' => ['pcs', 'box', 'set', 'pair', 'pack'],

];
