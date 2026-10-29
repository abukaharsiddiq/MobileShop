<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ram extends Model
{
    use Concerns\Audited;   // create/update/delete audit log e

    protected $fillable = [
        'ram_name',
        'slug',
        'status',
    ];
}
