<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rom extends Model
{
    use Concerns\Audited;   // create/update/delete audit log e

    protected $fillable = [
        'rom_name',
        'slug',
        'status',
    ];
}
