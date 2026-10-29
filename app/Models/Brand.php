<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use Concerns\Audited;   // create/update/delete audit log e

    protected $fillable = [
        'brand_name',
        'slug',
        'status',
    ];
}
