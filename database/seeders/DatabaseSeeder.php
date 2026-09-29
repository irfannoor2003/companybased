<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A fresh deployment is seeded with structural configuration only:
 *
 *   ModulesSeeder   the feature toggles
 *   SettingsSeeder  company name and branding
 *   PermissionsSeeder / RolesSeeder   the permission registry and its 7 roles
 *   UsersSeeder     the login accounts
 *
 * Deliberately not seeded, so no deployment inherits a developer's data:
 *   - the chart of accounts (an accountant sets this up per company, and no
 *     module auto-generates journal entries, so statements stay empty until then)
 *   - attendance rules, office GPS coordinates and holidays
 *   - mail identity and notification channel defaults
 *   - any business documents
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ModulesSeeder::class,
            SettingsSeeder::class,
            PermissionsSeeder::class,
            RolesSeeder::class,
            UsersSeeder::class,
        ]);
    }
}
