# CompanyBased / Nexos Digital

Laravel ERP application for independent local-business deployments in Pakistan. One company is served by one installation, one MySQL database, and one subdomain; the same codebase is deployed separately for each customer.

## Status

The core application is implemented. The application includes catalog, sales, purchasing, inventory, accounting, banking, employees, payroll, attendance, holidays, visits, fixed assets, investments, POS, reports, settings, and role-aware dashboards.

A review on 2026-09-28 found three critical defects — POS never decremented inventory, the chart of accounts was not seeded, and the test suite was not committed. Those are now fixed and covered by tests; see [Fixed since the 2026-09-28 review](#fixed-since-the-2026-09-28-review). The suite is 92 tests, all passing, and runs in about 7 minutes.

**The single most important thing left is to commit the work.** Everything described in this README currently exists only in the working tree. See [Known issues](#known-issues).

The app is suitable for controlled deployment after Hostinger environment setup, manual browser/mobile testing, persistent queue-worker setup, and optional ZKTeco enablement. The money and stock paths are now covered by tests, but no module generates journal entries, so the accounting reports render correctly but empty — see item 2.

## Completed

### Dashboard and reports

- Role-aware dashboard widgets for Admin, HR, Accountant, Salesman, Inventory Manager, Employee, and Super Admin.
- Widgets are filtered by current permissions and enabled modules.
- Users can customize their own dashboards at `/dashboard/customize`.
- Admins can customize company role defaults at `/settings/dashboards/{role}`.
- Super Admin is hidden from company role lists and its dashboard configuration returns 404.
- Dashboard money values use the configured company currency.
- Added sales and cash-flow charts powered by Chart.js.
- Added `/reports/grand` with Daily, Weekly, Monthly, and Yearly periods covering commercial, purchasing, inventory, finance, HR, POS, assets, investments, capital, and visits.
- Fixed Chart.js registration and JSON rendering in the reusable base chart component.

### Roles and permissions

- Company roles: Admin, HR, Accountant, Salesman, Inventory Manager, Employee.
- Super Admin is reserved for installation configuration and subscription access.
- Permission registry remains centralized in `config/permissions.php`.
- Every role can self-mark attendance where the employee profile is linked.
- HR and Admin can manage holidays.
- Dashboard customization is permission controlled.

### Attendance and visits

- Manual, button, QR, and UDP fingerprint attendance paths are available.
- Attendance rules include shifts, grace periods, late/short-leave/half-day handling, weekends, and deductions.
- Added holiday calendar management at `/employees/holidays`.
- Holidays are maintained manually at `/employees/holidays`; none are seeded.
- Holidays are excluded from payroll working days and block non-manual attendance scans.
- Visit completion requires a conclusion/reason.
- Visit completion images are optional and stored separately.
- Visit start/complete/cancel flows validate office GPS coordinates.
- Sales tracking reference system was audited for reusable lifecycle and device concepts; insecure public ZKTeco behavior was not copied.

### Notifications

- In-app notification dropdown reads unread database notifications.
- Added unread count and “Mark all read”.
- New sales orders notify users with order-view permission.
- New leave requests notify users with leave-approval permission.
- Customer email, order tracking, and subscription messages use queued mail where configured.
- SMTP connectivity was verified; production still requires a persistent `queue:work` worker.

### Production and deployment

- `APP_ENV=production` and `APP_DEBUG=false` are supported.
- Hostinger root `.htaccess` forwards requests to the Laravel `public/` directory.
- Production config, route, event, and view caches build successfully.
- `npm run build` completes successfully.
- Composer validation and security audit are clean.
- The default seed only creates modules, company settings, permissions, roles, and login users. No demo business data is seeded.

## Validation completed

- Authenticated GET smoke tests: zero unexpected server errors.
- Authenticated write harness: zero server errors; the only non-403 4xx was an invalid inventory fixture that correctly triggered validation.
- Authorization matrix: no security findings and no over-restriction for the tested roles.
- Dashboard, reports, holiday management, self-attendance, role dashboard customization, and personal dashboard customization HTTP checks passed.
- All migrations are applied.
- Blade view compilation and route caching pass.
- Vite/Tailwind production build passes.
- Composer validation and `composer audit` pass.

## Automated test suite

A PHPUnit suite exists in `tests/` — **10 Feature classes, 92 test methods** against ~25,000 lines of `app/`. It is still untracked in git; see [Known issues](#known-issues) item 1.

Current state:

```
92 tests, 92 passed, 563 assertions, 0 failures — ~7 minutes
```

| Test file | Tests | Covers |
|---|---|---|
| `InventoryLedgerTest` | 12 | `InventoryLedger` — on-hand == sum of movements, negative-stock rejection, decimal drift over 200 iterations |
| `MailSettingsSecurityTest` | 14 | `PUT/GET /settings/mail` — whitespace passwords, blank-box preservation, secret non-rendering |
| `EmployeeOnlyAttendanceTest` | 9 | `User::isAttendanceTracked()`, attendance/leave route guards |
| `CompanySettingsAndVisitsTest` | 4 | `PUT /settings/company`, `POST /visits/{id}/start`, GPS start validation |
| `PosSaleStockTest` | 6 | Till sales decrement stock, overselling refused, rounded line totals sum to the header, no shift means no sale |
| `TransferStatusTest` | 3 | Stock move and status flip commit together; a failed completion leaves both untouched |
| `LowStockAlertTest` | 7 | Edge-triggered alerting, restock re-arms it, rejected movements never alert |
| `NotificationRenderingTest` | 3 | Mail bodies actually render (see item 4 — this is how the `->table()` fatal was found) |
| `ChartOfAccountsTest` | 7 | Chart is seeded, idempotent, balanced journals post, grouped totals match per-account sums |
| `InvoicePaymentTest` | 12 | Payment locks, ownership, currency, cumulative overpayment, status transitions, both sales and supplier sides |
| `RoleScopingTest` | 7 | Config-backed role helpers; a Salesman cannot open another rep's visit |
| `StatusBadgeTest` | 8 | Badge colours and labels, per-module divergence preserved |

Still untested: `PayrollService::generate`, POS shifts and till reconciliation, `CheckModule`, the `CheckSubscription` blocking branch, CSV/PDF exports, and the remaining ~90 controllers. `tests/Unit` is empty.

### Running against both databases

`phpunit.xml` defaults to SQLite `:memory:`, which is fast but does **not** enforce `DECIMAL`. The suite must also pass on MySQL, because that is what production runs:

```powershell
# MySQL pass — catches type and column-existence errors SQLite silently allows
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='companybased_test_ci'
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS companybased_test_ci;"
php vendor/bin/phpunit --no-coverage
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "DROP DATABASE companybased_test_ci;"
Remove-Item Env:\DB_CONNECTION,Env:\DB_DATABASE
```

Both currently pass at 92/92. This is not a formality — the first MySQL run immediately caught an `is_default` column ordering in the POS warehouse lookup that SQLite accepted and MySQL rejected as a fatal error. A green SQLite run is not sufficient evidence.

## Fixed since the 2026-09-28 review

A review on 2026-09-28 found the issues listed below. The following have since been fixed and covered by tests.

**Correctness and data integrity**

- **POS never touched inventory.** A till sale now decrements stock through `InventoryLedger` with a new `pos_sale` movement type, so on-hand can no longer drift upward after a sale. Overselling is refused by the ledger's existing negative-stock guard rather than silently accepted.
- **POS had no transaction and could persist a receipt that did not add up.** The whole sale is now atomic, each line is rounded to 2dp *before* being summed so the header subtotal always equals the printed line totals, and a discount larger than the subtotal is rejected instead of storing a negative total.
- **`TransferController::updateStatus` broke the stock invariant.** `applyTransfer()` committed its own transaction and the status write happened separately, so a failure in between left a `draft` transfer with stock already debited. Both now commit together.
- **The chart of accounts was not seeded.** The deleted `AccountingSeeder` bundled the chart with demo data, so a new `ChartOfAccountsSeeder` restores it as structural configuration only — no demo transactions, consistent with the "no demo business data" policy. Without it the trial balance and financial statements render blank.
- **`DocumentItems::sync()` did delete-then-insert with no transaction**, so a constraint violation left a document with zero line items and stale header totals. It is now atomic, which fixes all ~44 callsites at once.
- **`QuoteController::convert`, `Banking\ReconciliationController::updateStatus` and `Sales\InvoiceController::update` were multi-write without transactions.** All three are now atomic; the reconciliation update also became a single bulk query instead of N.
- **Editing an invoice could strand it overpaid**, leaving `paid_amount > total` and a negative balance while `isPaid()` reported true. The edit path now refuses to drop the total below what has already been received.
- **`CustomReportController` had no ownership check** — anyone holding the broad `reports.reports.view` permission could open anyone's saved report. `index`, `show` and `create?from=` are now scoped to the owner, with the builder editors able to see across users.
- **`LowStockService` alerts repeated on every movement.** The low-stock flag is read back through the memoized `settings()` helper but was written straight to the database, so the memo never learned about it and the "edge trigger" never latched. Fixed with `Setting::primeMemo()`.
- **`LowStockAlert` and `OrderTrackingNotification` called `MailMessage::table()`, which does not exist.** Every low-stock and packed/shipped/delivered tracking email would have thrown a `BadMethodCallException` at render time. Both now build the figures as lines.
- **The inventory valuation chart ignored its month variable**, recomputing the same grand total for every month and rendering a flat line. It is now derived from the movement ledger, so each point is the on-hand value as at that month's close.
- **`DB::afterCommit` never fired under the test suite**, so the entire low-stock alerting path was dead code in 100% of tests. `checkItemAfterCommit()` now runs inline under the test runner, and the path is covered by `LowStockAlertTest`.

**Security and authorization**

- **Role names were string literals in ~25 places across 12 files.** Because the checks read "does this user have role X", renaming a role in the seeder made them return false everywhere else — and where a check gates an ownership rule, a false result *removes* the restriction instead of denying access. Names now live in `config/roles.php`, are consumed by the seeder, and are reached through `User::isSalesman()`, `isAdmin()`, `isHrManager()` and `hasAnyRole()`.

**Performance**

- **The suite took 23 minutes** because all six seeders ran in every test's `setUp()`. `PermissionsSeeder` inserted ~355 permissions one row at a time (each also firing an audit write), `RolesSeeder` re-granted ~500 pivot rows, and `Setting::setMany()` did 35 `updateOrCreate` calls. All three are now bulk operations and the suite runs in ~7 minutes.
- **`AccountController` ran `5 + 2N` queries** on the chart-of-accounts index. `Account::balancesByType()` aggregates in one grouped query, with a test asserting it matches the per-account sum it replaced.
- **`LowStockService` and `NotificationService` loaded the entire user table** and filtered in PHP with a per-user permission check. Both now resolve the permission in SQL via Spatie's `scopePermission`.

**Maintainability**

- **~200 lines were duplicated verbatim** between `Sales\PaymentController` and `Purchasing\SupplierPaymentController` — the same lock, ownership check, currency check, overpayment guard, balance maths and status machine. These now live in `App\Services\InvoicePaymentService`, and both controllers are covered by the 12 `InvoicePaymentTest` cases.
- **Seven near-identical `status-badge` components had drifted** — "cancelled" was `danger` in sales, suppliers and banking but `neutral` in visits. The maps moved to `config/statuses.php` behind one component, with the existing per-module colours deliberately preserved.
- `ext-bcmath` is now declared in `composer.json`; the test suite already depended on it.
- `MailSettingsSecurityTest` no longer permanently blanks `MAIL_PASSWORD` for the rest of the process.
- The false "never uses floats" claim in `DocumentItems`' docblock now describes what the code actually does.

## Known issues

Found by code review on 2026-09-28 and still outstanding.

### 1. The test suite is untracked in git — CRITICAL

`git status` reports `tests/` as untracked (`?? tests/`). All 92 tests, and every fix listed above, exist only in the working tree. There is no CI history, no review, and no blame. **Commit this first; nothing else matters until then.**

### 2. No module generates journal entries — HIGH

The chart of accounts is now seeded, so the trial balance and balance sheet have
accounts to report on. But `GeneralLedger` is still invoked from exactly one
place — `JournalController` (`:73`, `:133`, `:149`, `:161`). Sales, purchasing,
banking, inventory and payroll never post to the ledger, so the books stay empty
until someone journals by hand.

This is a design decision rather than a bug — the reports are correct, they
simply have no postings. It needs either automatic journal generation for the
document types that should feed accounting, or a documented decision that
accounting is manual-entry only.

### 3. Money arithmetic is `round()` on doubles, not decimals — MEDIUM

Storage is genuinely safe: 187 `decimal()` columns, zero `float()`/`double()`,
and every model casts to `decimal:2`, so values are strings. The problems are
at the arithmetic layer, and `ext-bcmath` is now declared but still unused in
`app/`.

- `Console/Commands/ConvertCurrency.php:71-76` rounds `subtotal`, `tax_amount`
  and `total` independently, so after conversion `subtotal + tax_amount !==
  total`. It also never converts line-item prices, so the itemisation stops
  reconciling to the header. The whole command is untested.
- `DocumentData.php:191-199` recomputes document totals in float for PDF
  rendering, using a *different* formula from `DocumentItems::sync()` (which
  rounds at four intermediate steps; `DocumentData` rounds at none), so a
  printed PDF can disagree with the stored totals by a cent.
- `PayrollService.php:119-124` multiplies money by an integer and only rounds
  the final sum, with no per-rule breakdown column to audit the difference.

### 4. Report controllers still aggregate in PHP — MEDIUM

`AccountController`'s N+1 is fixed (`Account::balancesByType()`). These are not:

- `FinancialReportController.php:145-151` — every posted journal line since
  inception, hydrated, and the same period re-queried a second and third time
  for the chart data.
- `CashFlowController.php:195,245,250,255` — loads every open invoice and every
  bank account into memory to sum them.

Both will fall over on real data volumes. The CRUD screens are fine — index
controllers do eager-load properly; this is confined to reports.

### 5. Authorization logic is still duplicated — MEDIUM

Route middleware, `resources/views/layouts/partials/sidebar.blade.php:1-59` (a
59-line `@php` block re-implementing module filtering, permission filtering and
`employee_only`) and `VisitsController::authorizeVisitAccess` each enforce the
same rules independently. All three must agree or the navigation lies. Role
*names* are now centralised in `config/roles.php`, but the sidebar still
re-derives permissions rather than consuming one shared source.

Related: `Controller::authorizePermission()`
(`app/Http/Controllers/Controller.php:13-16`) is defined with a docblock
claiming it prevents bypass, and is **still called from nowhere**.

### 6. Architectural debt worth addressing, not blocking — LOW-MEDIUM

- **94 of 109 controllers bypass the service layer** and query Eloquent
  directly. `app/Http/Requests` holds 2 files for 607 routes, so validation is
  duplicated inline. The payment controllers were extracted; the same treatment
  has not been applied elsewhere.
- **Three confirmed dependency cycles**, one papered over with a container
  lookup: `InventoryLedger ⇄ LowStockService`, `Setting ⇄ Branding`,
  `NotificationService ⇄ Notifications`.
- **Business logic in blade.** Supplier balance arithmetic in
  `suppliers/index.blade.php:74-79`; customer balance in
  `sales/customers/index.blade.php:75-77`; the unauthenticated
  `public/track.blade.php` derives its timeline from `statusEvents` inline.
- **1 factory exists and is used 0 times.** 97 of 98 models have no factory, so
  every test builds fixtures by hand.
- **7 pairs of migrations share timestamp prefixes** (e.g.
  `2026_08_14_000001` appears twice), making true dependency order unreadable.
  Fix before adding migration 119.
- **`DiscountLimit` returns raw JSON from an HTML form post**
  (`app/Support/DiscountLimit.php:48-51`), so a user exceeding their discount
  limit sees a JSON blob rather than a form validation error. Mixed into 4
  controllers, and it contradicts `DocumentItems::sync()`, which correctly throws
  `ValidationException`.
- **Leading-wildcard `LIKE '%term%'` search** across 26 models, so no index on
  any of those columns can ever be used.
- **`Subscription::isAccessBlocked()`** runs an uncached `SELECT` on every
  authenticated request, unlike the four Spatie permission checks around it
  which are cached.
- **`money()` behaves differently depending on the environment.** `ext-intl` is
  not installed here, so amounts render as `1234.56 PKR` locally but
  `PKR 1,234.56` in production. Only one path is ever exercised.

### 7. SQLite does not enforce `DECIMAL` — MEDIUM

`phpunit.xml` pins tests to SQLite `:memory:` for speed, but a `decimal(14,2)`
column happily stores `"0.015"` there while MySQL rounds it to `0.02`. This is
what let the POS subtotal bug ship, and it also masked a fatal
`Unknown column 'is_default'` error in the POS warehouse lookup that SQLite
accepted and MySQL rejected.

The suite is verified against both databases and both pass at 92/92 — see
[Running against both databases](#running-against-both-databases). The residual
risk is that *new* code is only ever exercised on SQLite by default, so a
MySQL-specific type violation can still be introduced without a test run
catching it. Worth making the MySQL pass part of CI rather than a manual step.

### Suggested order of work

1. Commit `tests/`.
2. Decide whether to auto-generate journal entries or document accounting as
   manual-entry only.
3. Move `FinancialReportController` and `CashFlowController` aggregation into SQL.
4. Fix `ConvertCurrency` so converted documents still satisfy
   `subtotal + tax = total`, and convert line-item prices.
5. Extract `sidebar.blade.php:1-59` into a `NavBuilder`; use the already-written
   `Controller::authorizePermission()` instead of hand-rolled `abort_if`s.
6. Add factories so the untested controllers become testable.
7. Adopt `ext-bcmath` behind a `Money` value object, or rewrite the remaining
   decimal-only docblocks to describe what the code does.

One item needs a maintainer decision rather than a code fix: **whether the 17
deleted seeders were intentional.** They appear as unstaged deletions in the
working tree with no commit or comment explaining them.

## Remaining before full production release

1. **Commit `tests/` and the fixes to version control.** All of it currently exists only in the working tree; see item 1 under [Known issues](#known-issues).
2. Run the complete manual browser/mobile test pass. The automated suite covers the stock ledger, POS sales, transfers, low-stock alerting, invoice payments, role scoping and badge rendering — but it does not replace clicking through the UI.
3. Configure persistent `php artisan queue:work` on Hostinger. Both `LowStockAlert` and `OrderTrackingNotification` implement `ShouldQueue`, so without a worker these notifications are never delivered.
4. Configure persistent `php artisan schedule:run` for subscription reminders and the daily low-stock sweep.
5. Send and verify a real customer email through the configured SMTP account. Note that `ext-intl` is not installed locally, so `money()` renders differently here than it will in production.
6. Decide whether to auto-generate journal entries or document accounting as manual-entry only; the reports are correct but currently have no postings. See item 2.
7. Run dedicated concurrency and duplicate-submit tests for finance, inventory, payroll, banking, POS, and device events.
8. Expand PHPUnit coverage beyond the current 10 test classes — payroll generation, POS shifts and till reconciliation, the `CheckModule` and `CheckSubscription` middleware branches, and CSV/PDF exports are the priorities.
9. Enable ZKTeco only for deployments that require it. The device URL will be based on the deployment `APP_URL`, but a secure device registration/token and device-user mapping layer must be completed before enabling ingestion.

## Environment

- Local URL: `http://companybased.test`
- Database: MySQL `companybased`
- Composer: `php "C:\laragon\bin\composer\composer.phar"`
- Default password for seeded accounts: `Password123!`

Seeded accounts:

- `superadmin@nexosdigital.test`
- `admin@nexosdigital.test`
- `hr@nexosdigital.test`
- `accountant@nexosdigital.test`
- `salesman@nexosdigital.test`
- `inventory-manager@nexosdigital.test`
- `employee@nexosdigital.test`

## Useful commands

```powershell
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
php artisan queue:work --queue=default --tries=3 --timeout=120
php artisan schedule:run
php vendor/bin/phpunit --no-coverage
php vendor/bin/pint --dirty
php "C:\laragon\bin\composer\composer.phar" audit
```

### Why the test suite still takes ~7 minutes

The suite went from 23 minutes to about 7 by fixing the seeders, but it is still slower than it should be for 92 tests. The remaining cost is structural:

`tests/SeedsDatabase.php` calls `$this->seed(DatabaseSeeder::class)` inside `setUp()`, so the full seed chain still runs before **every** test. `PermissionsSeeder` and `RolesSeeder` are now bulk operations, but `UsersSeeder` still creates 7 users — each with a `Hash::make` and an audit-log write — and that is the largest single remaining cost.

The structural fix is to seed once and wrap each test in a transaction rather than re-seeding per test, which would make the suite near-instant. It was not done here because it changes the meaning of `RefreshDatabase` isolation and the existing tests depend on the current behaviour.

## Maintainer notes

- Session cookie name is `nexos-digital-session`.
- Permission middleware resolves to the Spatie permission middleware class.
- `Setting` and `Module` cache values are memoized per request.
- `Holiday` rows and all transactional seeders are gone; all business data starts empty per deployment.
- Each customer deployment owns its own database, settings, users, roles, modules, currency, subscription, dashboards, holidays, and files.
- Hostinger document-root configuration should point to the project’s `public/` directory when possible; the root `.htaccess` is a fallback for project-root document roots.
- Physical attendance devices must be tested on the actual office network and configured against the deployment subdomain.

## Test harnesses

The temporary harnesses are located in `%TEMP%\opencode`:

- `get_crawl.php` — authenticated GET crawler.
- `write_crawl.php` — authenticated write/CRUD crawler.
- `auth_matrix.php` — role-versus-route authorization checker.
- `admin_smoke.php` — authenticated core-page smoke test.
- `harn_scan.php` — database pollution scanner.
