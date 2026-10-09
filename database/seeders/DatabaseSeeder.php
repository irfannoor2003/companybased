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
 *   ChartOfAccountsSeeder   the default ledger accounts the posting rules need
 *
 * Deliberately not seeded, so no deployment inherits a developer's data:
 *   - attendance rules and office holidays
 *   - mail identity and SMTP credentials (Super Admin sets these under
 *     Settings → Mail Server)
 *   - any business documents
 *
 * The chart of accounts used to be excluded on the grounds that no module
 * auto-generated journal entries, so an empty ledger was harmless. Now that
 * App\Services\Accounting\LedgerPosting posts automatically, the ledger cannot work
 * without accounts, so a working default set is seeded. An accountant should still
 * review and extend it before real books are kept against it.
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
            ChartOfAccountsSeeder::class,
            UsersSeeder::class,
        ]);
    }
}
