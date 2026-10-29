<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Application error (ekoi jaygar error ekta row, koto bar holo). Message theke gopon tottho muche rakha.
class SystemError extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }
}
