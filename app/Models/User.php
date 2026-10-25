<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{

    use HasFactory, Notifiable, HasRoles;

   protected $fillable = [
    'name',
    'email',
    'phone',
    'address',
    'password',
    'role',
    'branch_id',
    'status',
    'added_by',
    'updated_by',
];

   
    protected $hidden = [
        'password',
        'remember_token',
    ];

   
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // config/roles.php er role gulo
    public static function availableRoles(): array
    {
        return array_keys(config('roles'));
    }

    // admin chara baki role (staff menu theke banano/permission deya jay)
    public static function staffRoles(): array
    {
        return array_values(array_diff(self::availableRoles(), ['admin']));
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return (int) $this->status === 1;
    }

    public function roleLabel(): string
    {
        return config("roles.{$this->role}.label", ucfirst((string) $this->role));
    }

    // Login er por / access na thakle kon page e jabe
    public function homeRoute(): string
    {
        return config("roles.{$this->role}.home", 'sales.dashboard');
    }

    /**
     * Branch access: admin, 'access all branches' permission ache, ba kono branch e bandha na
     * (branch_id NULL) hole shob branch. Na hole shudhu nijer branch.
     */
    public function canAccessAllBranches(): bool
    {
        return $this->isAdmin() || !$this->branch_id || $this->can('access all branches');
    }

    // null = shob branch
    public function allowedBranchIds(): ?array
    {
        return $this->canAccessAllBranches() ? null : [(int) $this->branch_id];
    }

    // Branch chara (purono) record shobai dekhte pare, jate legacy stock atke na jay
    public function canAccessBranch(?int $branchId): bool
    {
        return $branchId === null || $this->canAccessAllBranches() || (int) $this->branch_id === $branchId;
    }

}
