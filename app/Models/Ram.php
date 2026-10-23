<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ram extends Model
{
    protected $fillable = [
        'ram_name',
        'slug',
        'status',
    ];
}
