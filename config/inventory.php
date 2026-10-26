<?php

return [

    /*
    | Low stock: variant er nijer threshold, na thakle product er, na thakle ei default.
    | Quantity eitar soman ba kom hole "Low Stock" (0 hole "Out of Stock").
    */
    'low_stock_threshold' => (int) env('LOW_STOCK_THRESHOLD', 5),

    /*
    | Purchase approval: false hole 'create purchase' thaka user save er somoy e approve korte pare
    | (ager moto stock sathe sathe bare). true hole shudhu 'approve purchase' thaka user approve kore,
    | baki der purchase "Pending Approval" e thake. Draft shob shomoy rakha jay.
    */
    'purchase_requires_approval' => (bool) env('PURCHASE_REQUIRES_APPROVAL', false),

    // product unit (form er select ar validation)
    'units' => ['pcs', 'box', 'set', 'pair', 'pack'],

];
