<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Database backup er hisab: file, sha256, encrypted, table/row, jachai ar restore er fol.
class Backup extends Model
{
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const PRUNED = 'pruned';

    // trigger: kon dhoron
    public const TYPES = [
        'manual'         => ['Manual', 'primary'],
        'automatic'      => ['Automatic', 'info'],
        'uploaded'       => ['Uploaded', 'secondary'],
        'before_restore' => ['Before restore', 'warning'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean', 'row_counts' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'verified_at' => 'datetime', 'restore_tested_at' => 'datetime', 'restored_at' => 'datetime', 'pruned_at' => 'datetime'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function path(): string
    {
        return rtrim(config('backup.path'), '/\\') . DIRECTORY_SEPARATOR . $this->filename;
    }

    public function exists(): bool
    {
        return $this->status === self::COMPLETED && is_file($this->path());
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->trigger][0] ?? ucfirst((string) $this->trigger);
    }

    public function typeColor(): string
    {
        return self::TYPES[$this->trigger][1] ?? 'light';
    }

    public function rows(): ?int
    {
        return $this->row_counts ? array_sum($this->row_counts) : null;
    }

    public function sizeLabel(): string
    {
        return self::bytes((int) $this->size);
    }

    public static function bytes(int|float $size): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($size < 1024 || $unit === 'GB') {
                return round($size, 1) . ' ' . $unit;
            }
            $size /= 1024;
        }

        return '';
    }
}
