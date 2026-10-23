<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'sale_invoice_prefix',
        'sale_invoice_number',
        'purchase_invoice_prefix',
        'purchase_invoice_number',
        'service_invoice_prefix',
        'service_invoice_number',
        'office_in_time',
        'office_out_time',
        'copyright_text',
        'developed_by',
        'logo',
    ];
}
