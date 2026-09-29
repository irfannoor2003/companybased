<?php

/*
|--------------------------------------------------------------------------
| Role Names
|--------------------------------------------------------------------------
|
| The canonical company role names. These used to be string literals scattered
| across controllers, models, views and the seeder. Because most of the checks
| are of the form "does this user have role X", a rename in the seeder silently
| made the check return false everywhere else — and where the check gates an
| ownership rule (a Salesman may only manage their own visits), a false result
| removes the restriction instead of denying access. Centralising the names
| makes the seeder and every consumer agree.
|
| config/permissions.php remains the single source of truth for *permissions*;
| this file is the equivalent for the role names those permissions attach to.
|
*/

return [

    'super_admin' => 'Super Admin',
    'admin' => 'Admin',
    'hr' => 'HR',
    'accountant' => 'Accountant',
    'salesman' => 'Salesman',
    'inventory_manager' => 'Inventory Manager',
    'employee' => 'Employee',

    /*
     | Roles that represent people on the payroll. These need a linked Employee
     | record before they can mark attendance, appear on payroll, or request
     | leave. Admin, Super Admin and Accountant are back-office accounts and are
     | deliberately excluded.
     */
    'staff' => ['HR', 'Salesman', 'Inventory Manager', 'Employee'],

    /*
     | Roles that can see and manage every record rather than only their own.
     */
    'supervisory' => ['Super Admin', 'Admin'],

];
