<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'password', 'first_name', 'last_name', 'phone', 'avatar', 'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected string $auditModule = 'settings';

    protected $auditExclude = ['updated'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('roles.super_admin'));
    }

    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->hasRole(config('roles.admin'));
    }

    /**
     * Whether the user holds any of the given canonical role keys.
     *
     * Accepts config keys (e.g. 'salesman') rather than display names, so a
     * rename in config/roles.php propagates to every call site.
     */
    public function hasAnyRole(array $roleKeys): bool
    {
        foreach ($roleKeys as $key) {
            if ($this->hasRole(config("roles.{$key}"))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A front-line sales rep: a staff member who may only manage their own
     * visits. Deliberately excludes admin and super admin, who supervise.
     */
    public function isSalesman(): bool
    {
        return ! $this->isAdmin() && $this->hasRole(config('roles.salesman'));
    }

    /**
     * Whether the user may manage other people's records in a module (leave
     * approval, HR actions).
     */
    public function isHrManager(): bool
    {
        return $this->isAdmin() || $this->hasRole(config('roles.hr'));
    }

    public function displayName(): string
    {
        if ($this->first_name && $this->last_name) {
            return "{$this->first_name} {$this->last_name}";
        }

        return $this->name;
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * The employee profile linked to this login, if any.
     */
    public function employeeProfile(): ?Employee
    {
        return $this->employee;
    }

    /**
     * Whether this login is a real, attendance-tracked employee.
     *
     * Super Admin and Admin are back-office accounts: they are not staff on the
     * payroll, so they have no employee profile and must never appear in
     * attendance, payroll or leave workflows.
     */
    public function isAttendanceTracked(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        $employee = $this->employeeProfile();

        return $employee !== null
            && (bool) $employee->attendance_enabled
            && $employee->employment_status === 'active';
    }

    public function initials(): string
    {
        $name = trim($this->displayName());

        $parts = preg_split('/\s+/', $name);

        return strtoupper(substr($parts[0] ?? 'U', 0, 1).substr($parts[count($parts) - 1] ?? '', 0, 1));
    }
}
