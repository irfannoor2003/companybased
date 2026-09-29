<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    /**
     * Roles that represent people on the payroll, and therefore need a linked
     * Employee record. Admin, Super Admin and Accountant are back-office
     * accounts: they are not staff, must never appear in attendance/payroll/leave,
     * and deliberately get no employee profile.
     */
    private const STAFF_ROLES_KEYS = ['hr', 'salesman', 'inventory_manager', 'employee'];

    public function run(): void
    {
        $staffRoles = array_map(fn (string $key): string => config("roles.{$key}"), self::STAFF_ROLES_KEYS);

        $superAdmin = Role::where('name', config('roles.super_admin'))->firstOrFail();
        $admin = Role::where('name', config('roles.admin'))->firstOrFail();
        $sampleRoles = [
            config('roles.hr'), config('roles.accountant'), config('roles.salesman'),
            config('roles.inventory_manager'), config('roles.employee'),
        ];

        $superAdminUser = $this->makeUser('Super Admin', 'superadmin@nexosdigital.test', 'Password123!');
        $superAdminUser->syncRoles([$superAdmin]);

        $adminUser = $this->makeUser('Admin', 'admin@nexosdigital.test', 'Password123!');
        $adminUser->syncRoles([$admin]);

        $department = $this->ensureDepartment();

        foreach ($sampleRoles as $roleName) {
            $slug = strtolower(str_replace(' ', '-', $roleName));
            $user = $this->makeUser($roleName, "{$slug}@nexosdigital.test", 'Password123!');
            $user->syncRoles([Role::where('name', $roleName)->firstOrFail()]);

            if (in_array($roleName, $staffRoles, true)) {
                $this->ensureEmployee($user, $roleName, $department);
            }
        }

        // Deactivate any users still assigned to removed roles (Auditor, Procurement)
        $removedRoles = ['Auditor', 'Procurement'];
        foreach ($removedRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                foreach ($role->users as $user) {
                    $user->roles()->detach($role->id);
                    $user->update(['is_active' => false]);
                }
            }
        }
    }

    /**
     * Create the Employee record a staff login needs before it can mark
     * attendance, request leave or appear on payroll. Idempotent: re-running the
     * seeder never creates a second employee for the same user.
     */
    private function ensureEmployee(User $user, string $roleName, ?Department $department): Employee
    {
        $parts = explode(' ', trim($user->name));
        $firstName = $user->first_name ?: ($parts[0] ?? $roleName);
        $lastName = $user->last_name ?: ($parts[count($parts) - 1] ?? 'User');

        return Employee::firstOrCreate(
            ['user_id' => $user->id],
            [
                'employee_code' => 'EMP-'.strtoupper(str($roleName)->replace(' ', '')->substr(0, 4)->value()),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $user->email,
                'date_hired' => now()->subYear()->startOfYear()->toDateString(),
                'department_id' => $department?->id,
                'job_title' => $roleName,
                'employment_status' => 'active',
                'attendance_enabled' => true,
            ]
        );
    }

    private function ensureDepartment(): ?Department
    {
        if (! Department::query()->exists()) {
            return null;
        }

        return Department::firstOrCreate(['name' => 'General']);
    }

    private function makeUser(string $displayName, string $email, string $password): User
    {
        $parts = explode(' ', trim($displayName));
        $firstName = $parts[0];
        $lastName = $parts[count($parts) - 1] ?? null;

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $displayName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );
    }
}
