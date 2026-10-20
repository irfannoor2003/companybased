<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A fresh deployment is seeded with structural configuration only:
 *
 *   ModulesSeeder   the feature toggles
 *   SettingsSeeder  company name and branding
 *   PermissionsSeeder / RolesSeeder   the permission registry and its 7 roles
 *   ChartOfAccountsSeeder   the default ledger accounts the posting rules need
 *   DocumentTemplatesSeeder   the document designs a fresh install prints with
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
 *
 * Document templates are seeded for the same reason. The ten rows on the development
 * database had been inserted by hand, so a fresh install opened Settings → Templates
 * on an empty page and the design controls looked absent.
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
            DocumentTemplatesSeeder::class,
            UsersSeeder::class,
        ]);
    }
}
