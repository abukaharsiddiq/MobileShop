<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokan (branch) ba warehouse. Warehouse e stock thake kintu POS bikri hoy na (transfer kore branch e).
 * "In Transit" (type transit) system location: transfer er pothe thaka stock - list/dropdown e ashe na
 * (global scope), Branch::transitId() diye pawa jay.
 */
class Branch extends Model
{
    use Concerns\Audited;   // create/update/delete audit log e

    const BRANCH = 'branch';
    const WAREHOUSE = 'warehouse';
    const TRANSIT = 'transit';

    const TYPES = [self::BRANCH => 'Branch (shop)', self::WAREHOUSE => 'Warehouse'];

    protected $fillable = ['name', 'type', 'code', 'phone', 'email', 'address', 'manager_id', 'is_default', 'status'];

    private static ?int $transitId = null;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('locations', fn (Builder $q) => $q->where($q->qualifyColumn('type'), '!=', self::TRANSIT));
    }

    // Login kora user er branch, na thakle default branch (thakle)
    public static function currentId(): ?int
    {
        return auth()->user()?->branch_id
            ?? static::where('is_default', 1)->where('status', 1)->value('id');
    }

    // transfer er pothe thaka stock er location
    public static function transitId(): int
    {
        return self::$transitId ??= (int) static::withoutGlobalScope('locations')->where('type', self::TRANSIT)->value('id')
            ?: throw new \LogicException('The "In Transit" location is missing. Run the migrations.');
    }

    public static function forgetTransit(): void
    {
        self::$transitId = null;
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function isWarehouse(): bool
    {
        return $this->type === self::WAREHOUSE;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    // user je branch/warehouse gulo dhorte pare (active)
    public function scopeAccessibleBy($query, User $user)
    {
        $ids = $user->allowedBranchIds();

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }
}
