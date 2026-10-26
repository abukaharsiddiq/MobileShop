<?php

return [

    // kistir frequency: kotodin/mash por por (form ar schedule ei list theke)
    'frequencies' => [
        'weekly'   => ['label' => 'Weekly', 'days' => 7],
        'biweekly' => ['label' => 'Every 2 weeks', 'days' => 14],
        'monthly'  => ['label' => 'Monthly', 'months' => 1],
    ],

    /*
    | Late fee (default bondho). Kisti due date + grace_days er por o baki thakle ekbar fee bose:
    |  - type 'fixed':   amount taka protita kisti te
    |  - type 'percent': kistir baki takar amount %
    | max (0 = shima nei) - ekta kisti te shorbochcho fee. Fee sale er additional charge hisebe due te jog hoy
    | (customer ledger e "Late Fee") ar kistir sathe aday hoy.
    */
    'late_fee' => [
        'type'       => env('INSTALLMENT_LATE_FEE_TYPE', 'none'),   // none | fixed | percent
        'amount'     => (float) env('INSTALLMENT_LATE_FEE_AMOUNT', 0),
        'grace_days' => (int) env('INSTALLMENT_LATE_FEE_GRACE_DAYS', 0),
        'max'        => (float) env('INSTALLMENT_LATE_FEE_MAX', 0),
    ],

    /*
    | Early settlement: shob baki ekshathe dile shorbochcho koto % discount deya jay (0 = discount nei).
    | Shudhu ekhono due na howa (bhobishyot er) kistir baki r upor; 'settle installment' permission lage.
    */
    'early_settlement_max_discount_percent' => (float) env('INSTALLMENT_EARLY_SETTLEMENT_MAX_DISCOUNT', 0),

    // reminder list e due date er koto din age theke dekhabe
    'reminder_days_before' => (int) env('INSTALLMENT_REMINDER_DAYS_BEFORE', 3),

    // reminder message (:name, :amount, :due_date, :invoice, :shop)
    'reminder_message' => 'Dear :name, your installment of :amount for invoice :invoice is due on :due_date. Please pay on time. - :shop',

];
