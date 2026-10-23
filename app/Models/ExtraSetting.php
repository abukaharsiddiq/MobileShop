<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtraSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'vat',
        'tax',
        'discount',
        'shiftment_cost'
    ];
}
