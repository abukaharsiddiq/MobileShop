<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['name', 'code', 'phone', 'email', 'address', 'is_default', 'status'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    // Login kora user er branch, na thakle default branch (thakle)
    public static function currentId(): ?int
    {
        return auth()->user()?->branch_id
            ?? static::where('is_default', 1)->where('status', 1)->value('id');
    }
}
