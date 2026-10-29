<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Color extends Model
{
    use Concerns\Audited;   // create/update/delete audit log e

    protected $fillable = [
        'color_name',
        'slug',
        'status',
    ];
}
