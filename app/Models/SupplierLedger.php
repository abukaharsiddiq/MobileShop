<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierLedger extends Model
{
      protected $fillable = [
        'supplier_id',
        'date',
        'transaction_type',
        'purchase_id',
        'reference_no',
        'debit',
        'credit',
        'balance',
        'note',
    ];
}
