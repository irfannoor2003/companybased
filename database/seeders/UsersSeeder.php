<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::where('name', 'Super Admin')->firstOrFail();
        $admin = Role::where('name', 'Admin')->firstOrFail();
        $sampleRoles = [
            'HR', 'Salesman', 'Inventory Manager', 'Accountant', 'Procurement', 'Auditor',
        ];

        $superAdminUser = $this->makeUser('Super Admin', 'superadmin@companybased.test', 'Password123!');
        $superAdminUser->syncRoles([$superAdmin]);

        $adminUser = $this->makeUser('Admin', 'admin@companybased.test', 'Password123!');
        $adminUser->syncRoles([$admin]);

        foreach ($sampleRoles as $roleName) {
            $slug = strtolower(str_replace(' ', '-', $roleName));
            $user = $this->makeUser($roleName, "{$slug}@companybased.test", 'Password123!');
            $user->syncRoles([Role::where('name', $roleName)->firstOrFail()]);
        }
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
