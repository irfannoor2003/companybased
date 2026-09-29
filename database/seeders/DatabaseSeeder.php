<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ModulesSeeder::class,
            SettingsSeeder::class,
            // Runs after SettingsSeeder: the chart of accounts takes its
            // currency from the company base currency. Without it the trial
            // balance and financial statements have no accounts to report on.
            ChartOfAccountsSeeder::class,
            NotificationRulesSeeder::class,
            PermissionsSeeder::class,
            RolesSeeder::class,
            UsersSeeder::class,
        ]);
    }
}
