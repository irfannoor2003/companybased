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
            PermissionsSeeder::class,
            RolesSeeder::class,
            UsersSeeder::class,
            CatalogSeeder::class,
            SalesSeeder::class,
            InventorySeeder::class,
            PurchasingSeeder::class,
            BankingSeeder::class,
            AccountingSeeder::class,
            EmployeesSeeder::class,
            VisitsSeeder::class,
            CapitalAccountsSeeder::class,
            FixedAssetsSeeder::class,
            InvestmentsSeeder::class,
            PosSeeder::class,
            TrackingAndNotificationsSeeder::class,
        ]);
    }
}
