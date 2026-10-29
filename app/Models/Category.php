<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use Concerns\Audited;   // create/update/delete audit log e

    protected $fillable = ['name','slug', 'status'];
}
