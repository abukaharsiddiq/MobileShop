<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Staff er HR profile (users row chhoy na). Sensitive field (salary, NID, payment account) shudhu nije ba
 * 'manage staff' (oi branch) dekhe - StaffService::canSeeSensitive.
 */
class EmployeeProfile extends Model
{
    const WORK_STATUSES = [
        'active'    => 'Active',
        'on_leave'  => 'On leave',
        'suspended' => 'Suspended',
        'resigned'  => 'Resigned',
    ];

    const SENSITIVE = ['nid_no', 'basic_salary', 'payment_account'];

    protected $fillable = ['user_id', 'employee_code', 'designation', 'joining_date', 'work_status', 'emergency_contact',
        'nid_no', 'basic_salary', 'payment_account', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['joining_date' => 'date', 'basic_salary' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workStatusLabel(): string
    {
        return self::WORK_STATUSES[$this->work_status] ?? ucfirst((string) $this->work_status);
    }
}
