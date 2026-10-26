<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'is_closed', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date'   => 'date',
            'is_closed'  => 'boolean',
            'closed_at'  => 'datetime',
        ];
    }

    public static function forDate(string $date): ?self
    {
        return static::where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
    }

    public static function current(): ?self
    {
        return static::forDate(now()->toDateString());
    }
}
