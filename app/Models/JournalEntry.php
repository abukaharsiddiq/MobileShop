<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    const POSTED = 'posted';
    const REVERSED = 'reversed';

    // live_key database e hishab hoy (generated column)
    protected $guarded = ['id', 'live_key'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy()
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversible(): bool
    {
        return $this->status === self::POSTED && !$this->reversal_of_id;
    }

    public function eventLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->event));
    }
}
