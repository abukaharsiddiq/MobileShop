<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rom extends Model
{
    protected $fillable = [
        'rom_name',
        'slug',
        'status',
    ];
}
