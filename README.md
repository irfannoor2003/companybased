# CompanyBased / Nexos Digital

Laravel ERP application for independent local-business deployments in Pakistan. One company is served by one installation, one MySQL database, and one subdomain; the same codebase is deployed separately for each customer.

> **Migration status: all 20 migrations applied and verified against real data
> (2026-10-09).** The tax-adoption migration has been run on the working
> database; legacy documents save correctly and unconfigured rates are still
> refused. See [Tax rate engine](#tax-rate-engine).

---

## Table of Contents

1. [Current Status](#current-status)
2. [Feature Overview](#feature-overview)
3. [Mail System](#mail-system)
4. [Document System](#document-system)
5. [Automated Test Suite](#automated-test-suite)
6. [Known Issues](#known-issues)
7. [Deployment Guide](#deployment-guide)
8. [Development Setup](#development-setup)
9. [Environment Reference](#environment-reference)
10. [Maintainer Notes](#maintainer-notes)

---

## Current Status

**Last verified: 2026-10-12 — full suite green (1,384 passing, 0 failures), all 23
migrations applied.** The wiring is mechanically verified: a census suite renders
every route, checks every auth guard, and proves every route name used in a Blade is
registered. **Three subsystems are backend-only and have no input screen yet** — see
[Not reachable from the interface](#not-reachable-from-the-interface) before calling
this finished. Nothing has been rendered in a browser, and no human has used it.

### Test suite

| Status | Count |
|---|---|
| Passing | **1,384 tests** |
| Assertions | **3,718** |
| Failures / errors | **0** |
| Skipped | 1 (`InvoicePdfSampleTest`, needs `SAMPLE_PDF_PATH` — a visual helper, not an assertion) |
| Wall clock | ~28 min (Feature suite; there is no Unit suite) |

> **Do not run two suites at the same time.** They share one MySQL schema
> (`companybased_test`). A concurrent run rebuilds it under the other and produces a
> wall of "table doesn't exist" and deadlock errors that look like real failures and
> are not. See [Running two suites at the same time](#running-two-suites-at-the-same-time).

**No test skips for a known defect.** The CALC-1/2/3 arithmetic skips, and the
CRITICAL-4 / AUTH-1 / AUTH-2 / HIGH-4 entries this file listed for several
revisions, are all closed — see
[The pinned defects](#the-pinned-defects). Regenerate that list from
`--display-skipped` rather than trusting prose.

Run with `--display-skipped` to print the live list of pinned known bug with the
README id each one belongs to; it is regenerated from the suite, never typed by
hand.

The suite runs on MySQL only (`companybased_test`, or `companybased_test_` with
`phpunit.sweep.xml`). See [Automated Test Suite](#automated-test-suite).

### What was done this session

#### Material requirements planning (NEW)

`App\Services\Inventory\MaterialRequirementsService` answers the question that
actually changes decisions — *what are we short of, and what has to be made or
bought to cover confirmed demand*. Screen at `/inventory/material-requirements`,
permission `inventory.material_requirements.view`.

The calculation is ordered, and the order is the whole point:

```
gross     = demand x BOM quantity x (1 + wastage)
available = on hand + on order - committed to production
net       = max(0, gross - available)
```

Each step prevents one specific, repeated waste:

| Step | Prevents |
|---|---|
| On hand first | Planning material for stock already on the shelf |
| Then on order | Buying the same steel twice |
| Then committed to production | Consuming the same components in two jobs |

Decisions worth knowing:

- **Demand is confirmed, unfulfilled sales orders only.** A draft order is not a
  commitment, and planning material for sales that may never happen is how a
  planner loses the business's trust in the plan.
- **Wastage is added to the requirement**, not treated as rounding. 5% of 1,000
  metres is 50 metres of shortfall discovered after the order was placed.
- **Stock reads through `InventoryLedger::onHand()`.** `inventory_items` has no
  `quantity_on_hand` column; reading from one would return 0 forever and every
  component would look permanently short.
- **Purchase orders net `qty - received_qty`**, not the full ordered quantity.
- **Only an active BOM is used**, newest version first — an order costed against an
  arbitrary recipe is unreproducible.
- **This is net requirements, not MRP.** No forward scheduling, no capacity
  planning, no demand forecasting. The screen is read-only by design: a plan built
  from unconfirmed demand must never place a purchase order on its own.

Covered by `MaterialRequirementsTest` (17) and `MaterialRequirementsUiTest` (5).

#### Production and manufacturing absorption (NEW)

**Production posting.** `App\Services\Accounting\ProductionPostingService` posts the
accounting side of a completed production order. Before this, completing an order
moved every stock figure in the system and none of the accounting ones — components
stayed on the balance sheet at their original cost and the finished goods had no cost
basis at all.

```
Cr Inventory              components, at their own cost
Cr Work in Progress       labour + overhead absorbed (only if any)
Dr Inventory              finished goods, at their own cost
Dr/Cr 6700 Variance       the balancing difference
```

- **Never credits Cost of Goods Sold.** Nothing has been sold. Relieving to finished
  goods *inventory* means COGS follows the sale, as everywhere else.
- **Components are valued at their own cost, never the goods' `cost_price`.** Using
  the goods' cost to value components would make every order balance by construction
  and every variance invisible — the opposite of what a variance account is for.
- **Accounts added:** `1350` Work in Progress, `1450` Manufacturing Overhead,
  `6700` Production Variance.

**Labour and overhead absorption.** `App\Services\Accounting\ProductionAbsorptionService`.
Before this, factory labour was expensed in one monthly total, so the cost of building
goods sat in period expense instead of in the goods — inventory understated and every
manufactured item's margin overstated by whatever it cost to make.

New table `production_labour_entries` (`2026_10_08_000001`) links time to the job
that consumed it. Attendance recorded minutes against an employee, production
recorded quantities against an order, and there was no path between them.

**The posting is a reclassification, not a new cost:**

```
Dr Work in Progress        labour + overhead absorbed
    Cr Salaries and Wages  labour, moving out of period expense
    Cr Manufacturing Overhead  overhead, moving out of period expense
```

This is the part worth arguing. An approved payroll run already posted
Dr 6200 / Cr 2150 Net Pay Payable, and that journal is correct — the wages were
incurred and the money is owed. Absorption therefore **credits the expense account,
6200**, to move cost across into inventory. The obvious alternative, crediting 2150
again, would leave the business owing staff twice for the same hours *and* leave the
cost fully expensed, counting it twice in total. Total cost is unchanged; only its
location on the balance sheet moves. That is what absorption means.

Also decided here:

- **The hourly rate is snapshotted onto the entry.** A later pay rise must not
  silently restate labour already posted into inventory.
- **No salary structure means no rate — `null`, not `0`.** Treating unknown as zero
  would absorb a worker's time into production at no cost.
- **Overhead is absorbed per direct labour hour**, rate from
  `production.overhead_rate_per_hour`, defaulting to **zero**. There is no work
  centre, machine or equipment record anywhere in this application, so a
  machine-hour rate would have nothing to measure against. Zero is the right default:
  absorbing overhead nobody configured would invent factory cost and put it in
  inventory.
- **Monthly-to-hourly conversion** uses `production.standard_monthly_hours`
  (default 208 = 26 days x 8h). Salary structures are monthly because payroll is
  monthly, but absorption is per hour, so the conversion is unavoidable and has to be
  one explicit number. Hard-coding 208 would misprice labour for every six-day week.
- **`1450` was retyped from `asset` to `expense`.** As an absorption control account
  its balance is *unabsorbed overhead* — a period cost. Leaving it as an asset parked
  factory overhead on the balance sheet.
- **Completion relieves WIP.** Labour accumulates there while a job runs and is
  transferred to the finished goods at completion. Without that relief the cost would
  sit in WIP for ever and be capitalised a second time on the next order.
- **`updateStatus()` absorbs before completing**, so a back-dated labour entry is
  never stranded in WIP with no order left to move it.

Covered by `ProductionPostingTest` (10) and `ProductionAbsorptionTest` (22).

#### Tax rate engine (NEW)

`App\Services\Accounting\TaxCalculator` with effective-dated rates in `tax_rates`
(`2026_10_07_000001`): sales/purchase/both, priority, thresholds, exemptions,
compound tax, and a ledger-account mapping per rate. Wired into all nine document
flows. Rates are **superseded, never edited in force**; future-dated rates can be
deleted and active ones disabled.

> **Migration hazard — RESOLVED by `2026_10_09_000001`.** The engine refuses a
> document line that charges tax with no configured rate behind it. That control is
> intended: posting 17% to the ledger with nothing defining which rate it was makes
> the return irreproducible. But it shipped with an empty `tax_rates` table, so a
> deployment already trading under the old hand-typed `tax_percent` was locked out
> of saving its own invoices — correcting a customer's address would fail with a tax
> error about a percentage they had been charged for a year.
>
> `2026_10_09_000001_adopt_existing_tax_rates.php` **adopts the rates those
> documents already charge**, dated from the earliest document that used each one.
> The control still holds — there is now a defined rate behind every historic
> figure — and no correct document has to change. Loosening the engine to allow
> unconfigured tax would have restored saving and put the problem straight back on
> the return.
>
> What the migration deliberately does *not* do:
>
> - **Adopt zero percentages.** Zero means "no tax on this line", which the engine
>   already accepts with no rate configured; adopting it would imply a rate was in
>   force when none was.
> - **Guess the ledger account or the rate's real name.** `account_id` is left NULL
>   and the row is named "Adopted ..." — a wrong account guess would misstate the
>   balance sheet, and the business's real code and threshold regime are things only
>   the owner knows. The owner renames and points it.
> - **Backfill over an operator's own catalogue.** If `tax_rates` is already
>   populated, nothing is adopted; they know their rates.
> - **Merge sales and purchase.** The same percentage charged on a purchase is
>   reclaimable input tax posting to a different account.
>
> Rates are dated from the **earliest** document, not from today — dated from today,
> history would still resolve to "no rate in force" and every already-issued
> document would remain un-saveable.
>
> Pinned by `TaxRateAdoptionMigrationTest` (14), which reproduces the original
> hazard as a failing case and proves adoption closes it.

Covered by `TaxRateEngineTest` (18), `TaxRateCrudTest` (16),
`TaxRateDocumentWiringTest` (12), `TaxRateAdoptionMigrationTest` (14).

#### Approval workflow (NEW)

`approval_requests` (`2026_10_06_000005`), `Approvable` trait and
`ApprovalRequest`. Maker-checker separation, rejection history, withdrawal, pending
edit guards, one live request per step. Wired to `ExpenseClaim`, which also had a
free-form status endpoint removed — it allowed unapproved reimbursement.

#### Fiscal periods, multi-currency and revaluation (NEW)

- `FiscalPeriod` / `FiscalPeriodService` — open -> closed -> locked, date locks,
  overlap prevention, monthly generation.
- Journal lines gained `currency`, `exchange_rate`, `original_debit`,
  `original_credit`. `LedgerPosting` converts centrally. This fixed foreign-currency
  documents that were posting face value as base currency.
- `RevaluationService` — closing-rate gain/loss on `6600` / `6610`, with correct
  signed direction for assets and liabilities, and `closeWithRevaluation()` ordering
  revaluation before the close.

#### Earlier in this batch

- **Bank GL integration** — bank-account-to-ledger mapping, transaction and transfer
  journals, account `2050` Uncategorised Bank Items, `journal_entry_id` on both.
  (Reconciliation itself already existed and was not missing.)
- **Payroll GL posting** — approval and payment are separate events. Added `2150`
  Net Pay Payable and `2250` Payroll Tax Payable. Attendance deductions credit back
  to expense rather than being fabricated as tax.
- **POS GL posting** — cash/card settlement mapping, contra-revenue discount account
  `4200`, COGS and inventory relief, and no invented cost for productless lines.
- **Sales order to invoice** — `SalesOrder::uninvoicedLines()` /
  `uninvoicedTotal()` / `liveInvoices()`. Invoice creation prefills only remaining
  quantities and refuses over-invoicing.
- **Receipt PDFs consolidated** into `partials/pdf/receipt.blade.php` +
  `App\Support\ReceiptDocument`, removing three duplicated templates.
- **Invoice PDF redesign** — the full-height dark rail was replaced with a restrained
  masthead, and discount and per-line tax were corrected against
  `DocumentItems::lineTotals()`.
- **Route and UI parity verified** — 627 named routes, no unknown
  permissions or missing views.
- **Nullable-array hardening** across 14 controllers.

#### Multi-identity mail system

Four named email identities are now fully supported and independently configurable through **Settings -> Mail Server**:

| Identity | Purpose | Setting prefix | Own SMTP login? |
|---|---|---|---|
| **System** | Automated/transactional mail -- welcome, alerts, password reset | `mail.system_*` | No |
| **Personal** | Staff-sent email from the portal (e.g. `CustomerEmail`) | `mail.personal_*` | No -- shares the default transport |
| **Accounts** | Invoices, receipts, billing reminders | `mail.accounts_*` | Yes -- `mail.accounts_username` / `_password` |
| **Sales** | Quotes, proposals, and sales correspondence | `mail.sales_*` | Yes -- `mail.sales_username` / `_password` |

The three registered transports are `smtp_accounts`, `smtp_sales` and `smtp_team`, each registered at boot only when both its `_username` and `_password` settings are non-empty.

**The Personal identity has no credential settings of its own.** `MailConfigServiceProvider` reads `mail.team_username` / `mail.team_password` for the third transport and names it `smtp_team`, while `MailIdentity::PERSONAL_PREFIX` is `mail.personal`. Those are two different namespaces on purpose: `mail.personal_*` configures the *sender* (address, display name, whether to use the shared mailbox) and `mail.team_*` configures the *login* used to send as that shared mailbox. On shared hosting every mailbox is a separate SMTP login even when they relay through one server.

**New mailables:**
- `SalesInvoiceMailable` -- sends an invoice PDF via the Accounts identity
- `SalesQuoteMailable` -- sends a quote PDF via the Sales identity
- `PurchaseOrderMailable` -- sends a purchase order PDF via the Accounts identity

**New send-email routes** (POST, auth-gated):
- `POST /sales/invoices/{id}/send-email`
- `POST /sales/quotes/{id}/send-email`
- `POST /suppliers/purchase-orders/{id}/send-email`

**UI additions:** Email buttons and confirmation modals added to invoice, quote, and purchase order show-views. The `x-modal` Blade component was upgraded to event-based toggling (`open-modal` / `close-modal` Alpine events) so multiple modals on the same page do not conflict.

**Covered by** `MultiEmailSetupTest` (7 tests), `MailTransportSchemeTest` (7 tests), `MailSettingsSecurityTest` (14 tests), `MailCredentialsFormTest`.

#### PDF and document design

- **Single shared design** -- `resources/views/documents/pdf.blade.php` renders every document type (quotes, orders, delivery notes, credit notes, recurring invoices, purchase quotes, purchase orders, debit notes, purchase invoices). Full-height brand-coloured left rail, boxed table header, boxed total row, signature block.
- **Sales invoice PDF** rebuilt to the same design. Per-template variants (`classic`, `modern`, `minimal`, `corporate`) are deleted; `InvoiceTemplate::view()` resolves them all to the single design so stored name values continue to work.
- **Live preview** recomputed in-browser to match the PDF: same rail colour (computed by `darken_hex()` helper), same section order, same boxed totals.
- **Print stylesheet** hides all nav and action buttons; printing an open preview produces one clean page.
- **`PurchaseInvoice`** registered in `DocumentData` so it uses the shared view.
- **`billToFor()` heading** fixed -- templates now read `heading`/`contact`/`address`/`tax`/`code`, not the stale `label`/`lines` keys.

#### Mail transport fix

`MailConfigServiceProvider` maps the panel encryption labels (`ssl`, `tls`, none) to valid Symfony Mailer DSN schemes (`smtps`, `smtp`). A wrong label no longer crashes the mail transport and no longer causes customer-creation to 500 after the row was already committed.

#### Bug fixes

- **Balance calculation** -- `SalesCustomer::balance()` and `Supplier::balance()` previously excluded `paid` invoices while still subtracting the payments that settled them. Fixed.
- **Cash flow chart** -- Carbon vs. string key comparison made every bucket zero. Fixed.
- **Payment-to-invoice reassignment** -- now allowed (matching Odoo/Xero/Sage), with audit on both invoices.
- **Invoice status machine** -- reversing a payment now correctly recalculates invoice status from remaining payment rows.
- **Document number generator** -- `next_document_number()` no longer skips every other number.
- **Currency on edit forms** -- 14 occurrences of `old(''currency'', ''currency'', $x)` fixed to `old(''currency'', $x)`.
- **Customer credit and overpayment** -- surplus over the invoice total is held as credit and auto-applied to the next invoice. `CustomerCreditTest` (10 tests).
- **Credit note application** -- `applied_amount` is now written by every code path that creates or approves a credit note. `CreditNoteSettlementTest` (6 tests).
- **Backup process** -- `protocolFlag()` return value spread fixed. `DatabaseBackupTest` (18 tests).

#### Later: crashes found by testing untested paths

Each of these returned HTTP 500 on a route the interface links to, and each was invisible because the affected page had no test.

| Defect | Effect |
|---|---|
| `documents/show.blade.php` used `route('quotes.send_email')` and `route('purchase_orders.send_email')` | Quote and purchase-order show pages 500 |
| `QuoteController` missing `use Illuminate\Support\Facades\DB` | Quote -> Order conversion 500 |
| `ProductionOrderController` used `\DB::raw()` with no global class alias | Completing a production order 500 -- the stock-consuming path |
| `SubscriptionController::activate()` read `$data['notes']` / `$data['plan_name']` directly | 500 on any request omitting those optional fields |
| `pos/receipts/index.blade.php` checked `pos.receipts.export` and `pos.receipts.show` | Neither permission exists in the registry, so POS receipts had **no export button and no way to open any receipt** |

The last one is the instructive case: `can()` returns `false` for an unknown permission, so the guarded markup is silently withheld rather than erroring. `ViewIntegrityTest` now fails the build on any undefined route name or permission string in any view or in `config/nav.php`.

Two related defects fixed at the same time:

- `SalesQuote` had no `salesman` relation and no `salesman_id` column, so `SalesQuoteMailable` read a relation that did not exist and `mail.sales_use_salesman_address` was inert for quotations. Added by migration `2026_10_03_000001`; quote conversion now carries the original rep onto the resulting order instead of crediting it to whoever pressed Convert.
- Duplicate `'number'` key in `$fillable` on `SalesQuote`, `PurchaseOrder` and `PurchaseInvoice`.

#### `currency:convert` was corrupting documents

Beyond the rounding problem originally recorded as HIGH-3, the command had two worse faults:

- It converted `paid_amount` but **not** the payment rows behind it. `InvoicePaymentService` recomputes `paid_amount` from `sum(payments.amount)` on every status recalculation, so the next recalculation after a conversion reverted a settled invoice to *partially paid*.
- It never converted line items, so the printed rows stopped matching the printed header.

It now converts line items first and derives the header from them via the shared arithmetic, converts payments in the same transaction, multiplies through `bcmul`, and previews unless `--force` is passed. `ConvertCurrencyTest` (11 tests) pins each invariant.

---

## Feature Overview

### Modules

| Module | Key capabilities |
|---|---|
| **Catalog** | Products, categories, units, price lists |
| **Sales** | Customers, quotes, orders, invoices, payments, credit notes, withholding tax receipts |
| **Purchasing** | Suppliers, purchase quotes, purchase orders, purchase invoices, supplier payments, debit notes |
| **Inventory** | Warehouses, stock transfers, inventory ledger, low-stock alerts, **weighted-average valuation** |
| **Accounting** | Chart of accounts, general ledger, trial balance, balance sheet, P&L, **year-end close to retained earnings**, **ledger-computed tax returns** |
| **Banking** | Bank accounts, bank transfers, reconciliation, cash flow |
| **Employees** | Employee profiles, attendance (manual / QR / button / UDP fingerprint), leaves, holidays, visits |
| **Payroll** | Payroll runs, payslips (itemised, printable), statutory deductions |
| **POS** | Till sessions, shifts, sales, variance, **80mm thermal receipts** |
| **Manufacturing** | **Multi-level BOMs**, **scrap and yield reporting**, **work centres and routings**, material requirements |
| **Fixed Assets** | Asset register, depreciation |
| **Investments** | Investment tracking |
| **Reports** | Grand report (daily/weekly/monthly/yearly), financial statements, cash flow |
| **Settings** | Company, mail server, modules, roles, permissions, backups, subscription |

### Roles

| Role | Notes |
|---|---|
| Super Admin | Installation configuration and subscription management only. Not a company role. |
| Admin | Full company access |
| HR | Employees, attendance, payroll, leaves |
| Accountant | Accounting, banking, financial reports |
| Salesman | Own sales pipeline; scoped to assigned records |
| Inventory Manager | Inventory, warehouses, transfers |
| Employee | Self-attendance and self-leave only |

Role names are centralised in `config/roles.php` and consumed everywhere via `User::isAdmin()`, `isSalesman()`, `isHrManager()`, `hasAnyRole()`.

### Permissions

387 permissions stored in `config/permissions.php`. 716 role-permission rows seeded at install. Enforced at route middleware and controller level via Spatie Laravel Permission.

---

## Mail System

### Architecture

```
Settings -> Mail Server (Super Admin)
           |
           v
    settings() table
           |
           v
MailConfigServiceProvider (boot)
  +- registers 'smtp_team'     when mail.team_username / mail.team_password are set
  +- registers 'smtp_accounts' when mail.accounts_username / mail.accounts_password are set
  +- registers 'smtp_sales'    when mail.sales_username / mail.sales_password are set
           |
           v
MailIdentity (static resolver)
  +- systemFrom*()       -> 'mail.system_*' settings -> no-reply@<domain>
  +- personalFrom*()     -> 'mail.personal_*' settings -> info@<domain>
  +- accountsFrom*()     -> 'mail.accounts_*' settings -> accounts@<domain>
  +- salesFrom*()        -> 'mail.sales_*' settings, optionally salesman user
           |
           v
CompanyMailable (base mailable)
  +- useAccountsIdentity()  -> sets from + mailer to Accounts
  +- useSalesIdentity()     -> sets from + mailer to Sales
```

### Identity resolution rules

**Accounts identity**

1. `mail.accounts_from_address` setting -> if set, use it
2. `accounts@<domain>` derived from the configured system sender
3. Fall back to the System from address

If both `mail.accounts_username` and `mail.accounts_password` are non-empty, the `smtp_accounts` transport is used. Otherwise the default transport is used.

**Sales identity**

1. If `mail.sales_use_salesman_address` is true **and** a `User $salesman` is passed with a non-empty email -> use that address and name
2. `mail.sales_from_address` setting -> if set, use it
3. `sales@<domain>` derived from the configured system sender
4. Fall back to the Personal from address

If both `mail.sales_username` and `mail.sales_password` are non-empty, the `smtp_sales` transport is used.

### Configuring department mailboxes

In **Settings -> Mail Server -> Department Email Addresses**:

| Field | Key stored | Description |
|---|---|---|
| Accounts From Address | `mail.accounts_from_address` | Sender address for invoices and billing |
| Accounts From Name | `mail.accounts_from_name` | Display name, e.g. "Nexos Accounts" |
| Accounts SMTP Username | `mail.accounts_username` | Credential for the separate SMTP login |
| Accounts SMTP Password | `mail.accounts_password` | Encrypted at rest via `encrypt()` |
| Sales From Address | `mail.sales_from_address` | Sender address for quotes and proposals |
| Sales From Name | `mail.sales_from_name` | Display name, e.g. "Nexos Sales" |
| Use Salesman Address | `mail.sales_use_salesman_address` | Toggle -- prefer the rep's own email |
| Sales SMTP Username | `mail.sales_username` | Credential for the separate SMTP login |
| Sales SMTP Password | `mail.sales_password` | Encrypted at rest |
| Team Mailbox Username | `mail.team_username` | Login for the shared `info@` mailbox (Personal identity) |
| Team Mailbox Password | `mail.team_password` | Encrypted at rest; backs the `smtp_team` transport |

The `mail.team_*` pair is documented here rather than in the identity table because it is the credential half of the Personal identity while living under a different prefix. `SecondMailboxLoginTest` covers it.

Passwords are stored encrypted and never rendered back into the form. Clearing a password field while leaving the username filled (or vice versa) is a validation error; both fields must be filled or both must be absent.

### Transport scheme normalisation

The panel stores `ssl`, `tls`, or _(blank)_. `MailConfigServiceProvider` maps these to valid Symfony Mailer DSN values:

| Panel value | DSN scheme |
|---|---|
| `ssl` | `smtps` |
| `tls` | `smtp` + `tls=true` |
| _(blank)_ | `smtp` |

### Sending a document by email

Each document show page has an **Email** button that opens a modal. The modal accepts a recipient address and an optional message body. On submit, the controller:

1. Validates the recipient address
2. Generates the document PDF via `DocumentData` + `Barryvdh\DomPDF\Facade\Pdf`
3. Dispatches the appropriate mailable (synchronously; queued delivery is opt-in)
4. Redirects back with a success flash

---

## Accounting

The ledger is no longer manual-entry only. `App\Services\Accounting\LedgerPosting`
raises a balanced, posted journal for each business event, and corrections void the
original and raise a replacement so both the mistake and its fix stay on the audit
trail.

| Event | Entry |
|---|---|
| Sales invoice issued | Dr AR gross / Cr Output tax / Cr Revenue / Dr COGS / Cr Inventory |
| Customer receipt | Dr Cash / Cr AR |
| Purchase bill received | Dr expense account per line / Dr Input tax / Cr AP |
| Supplier payment | Dr AP / Cr Bank |
| Inventory valuation loss | Dr Inventory adjustment / Cr Inventory |
| Payroll approved | Dr 6200 Salaries and Wages / Cr 2150 Net Pay Payable / Cr 2250 Payroll Tax Payable |
| Payroll paid | Dr 2150 / Cr Bank |
| POS sale completed | Dr Cash or Card / Cr Revenue / Cr Discount / Dr COGS / Cr Inventory |
| Bank transaction | Dr/Cr the mapped ledger account / Cr/Dr Bank |
| Production completed | Cr Inventory (components) / Cr WIP (absorbed) / Dr Inventory (goods) / Dr-Cr 6700 Variance |
| Labour absorbed | Dr 1350 WIP / Cr 6200 Salaries and Wages |
| Overhead absorbed | Dr 1350 WIP / Cr 1450 Manufacturing Overhead |
| FX revaluation | Dr/Cr the asset or liability / Cr-Dr 6600 or 6610 gain-loss |

Design rules:

- **Idempotent.** Every method is keyed on the document and is a no-op if that
  document already has a journal. Re-running, retrying, or an idempotent job never
  double-posts. Keyed **per lifecycle stage**, so a payroll run can accrue at
  approval and settle at payment without either blocking the other.
- **Never unbalanced.** Lines are built and checked in decimal arithmetic, and
  `GeneralLedger` independently refuses to post an entry that does not balance.
- **Void-and-repost, never edit in place.** Amending or deleting a document voids
  its journal and raises a new one.
- **Degrades rather than fails.** `LedgerPosting::isReady()` skips posting and logs
  a warning when the chart of accounts is incomplete, so a deployment seeded before
  the accounts existed keeps trading with an empty ledger instead of 500-ing.
- **Stock and ledger move together.** `ProductionOrderController::updateStatus()`
  applies the stock movement and posts the journal inside one `try`, so a posting
  failure leaves the two consistent with each other rather than one updated and the
  other not.

Bill lines carry the **expense account** they are charged to (`account_id` on
`purchase_invoice_items`), so the profit and loss separates rent, salaries and bank
charges instead of reporting everything as cost of goods sold. Sales lines snapshot
the product's `cost_price` as `unit_cost`, so repricing a product cannot rewrite a
past invoice's margin.

### Year-end close and retained earnings

`php artisan accounting:close-year --until=2026-12-31`

Retained earnings is read from account **3100**, not computed as
`assets - liabilities - equity`. The plug version forced the balance sheet to agree
by arithmetic whatever the ledger contained: a transposition, a misposting or a
missing entry simply moved the retained line to hide it. The balance sheet always
agreed, which meant it never told anybody anything.

The close moves the period's revenue and expense into 3100 — debit each revenue,
credit each expense, credit the net profit (or debit the loss). Only **posted**
entries dated on or before the period end are closed, so a draft in progress cannot
be folded into a closed year, and closing the same year twice is refused rather than
crediting retained earnings again with nothing left in the P&L to justify it.

Covered by `YearEndCloseTest` (7) and `DocumentPageNumberTest` (6).

### Weighted-average inventory valuation

Cost of goods sold was recognised at the product's `cost_price` — a figure someone
typed onto the product record. That is only correct if every purchase of that product
happened at the same price. A business buying the same item at 100 and then at 140 is
carrying stock worth neither, and the margin it reports on the sale in between is a
fiction.

```
On a receipt of Q at cost C against Q0 on hand at average A:
    A' = (Q0 x A + Q x C) / (Q0 + Q)
On a sale the average does NOT move — the method consumes no cost layers.
```

The average lives on the **inventory item**, because that is the level stock is
tracked at, and it is updated inside `InventoryLedger::adjust()` — the one place every
quantity change passes through. Purchase receipts and incoming shipments pass their
own cost; a receipt with no cost is ignored rather than blended in at zero, which
would write off everything already on the shelf. Manufacturing output feeds it too.

Covered by `WeightedAverageValuationTest` (11) and `CostOfGoodsSoldTest` (8).

### Tax returns

Every figure on a return used to be a required form field. That is the weakest
possible position for the most consequential document the business produces: the
return cannot be reproduced from the books that generated it.

`TaxReturnCalculator` derives them from the general ledger, **posted entries only**:

| Figure | Source |
|---|---|
| Output tax | Movement on 2200 — credits **minus** debits |
| Input tax | Movement on 1310 — debits **minus** credits |
| Taxable amount | Revenue accounts, credited net of tax |
| Gross receipts | Taxable amount + output tax |

A credit note debits 2200 and so reduces the period's output tax rather than
increasing it. `POST /accounting/tax-returns/{tax}/recompute` refreshes a **draft**;
a filed return is a historical statement and is refused.

Covered by `TaxReturnCalculatorTest` (11).

### Absorption

Manufacturing labour and overhead are capitalised into work in progress rather than
expensed in one monthly total, and transferred to the finished goods when the order
completes. The posting is a **reclassification** — it credits the expense account it
came from, so total cost is unchanged and only its location on the balance sheet
moves. It deliberately does **not** credit Net Pay Payable a second time; payroll
already created that liability and the wages are genuinely owed.

Full rationale, settings keys and the `setUp()` trap that silently breaks these
tests are in [Maintainer Notes](#maintainer-notes).

Covered by `LedgerPostingTest`, `LedgerReversalTest` (10),
`DocumentLedgerReversalTest` (6), `BillExpenseAccountTest` (8),
`CostOfGoodsSoldTest` (8), `PayrollLedgerPostingTest`,
`PosLedgerPostingTest`, `BankLedgerGlPostingTest`,
`MultiCurrencyLedgerPostingTest`, `RevaluationTest`, `FiscalPeriodTest`,
`ApprovalWorkflowTest`, `ProductionPostingTest` (10) and
`ProductionAbsorptionTest` (22).

### Still missing

These are scope limits, stated rather than hidden. None of them is a defect, and
none of them blocks the application being correct for the business it serves.

#### Not reachable from the interface

**The most important gap on this page.** Three subsystems have working services,
controllers and tests, but no screen to drive them. Each is currently reachable only
by POSTing to the endpoint directly:

| Feature | Backend status | Missing |
|---|---|---|
| **Scrap and yield reporting** | Columns, service, endpoint and display all work | **No input form.** `quantity_good`, `quantity_scrap` and per-component actuals must be POSTed to `PATCH /inventory/production-orders/{order}/status` by hand |
| **BOM routings** | `inventory_bom_operations`, models, release-on-order and capacity all work | **No editor.** A routing can only be created programmatically; the BOM form edits components only |
| **POS receipt printing** | Route, template and 80mm geometry all work | The **sale screen never links to it**. A cashier reaches it via the receipts list, not from the sale they just rang up |

Tests exercise all three by calling the service and the endpoint directly, so they
prove the logic and prove nothing about whether an operator can use it. Treat these
as **half-built**: the rules are right and the arithmetic is verified, but nobody can
perform the task in the application yet.

#### Deliberate scope

- **Absorption is still labour-hour based.** Work centres, machines and routings now
  exist and record planned against actual minutes, so a machine-hour basis is now
  *measurable* — but the basis itself has not been switched over, because a shop must
  choose it deliberately rather than have it change underneath them. Absorption is
  still manual: someone types minutes in, with no timesheet integration from
  attendance.
- **No forward scheduling.** Work centre capacity is reported per day and overloaded
  centres sort to the top, but there is no finite-capacity scheduler, no lead-time
  sequencing and no demand forecasting. MRP remains net requirements only.
- **Scrap is absorbed, not expensed.** A run rejecting 10 of 100 units carries the
  whole batch cost on the 90 that survive, which is what the material actually did.
  The difference surfaces on **Production Variance** against standard cost. There is
  no separate scrap expense line, and no rework or repair order type.
- **`tax_rates.account_id` is informational.** Posting uses the fixed output and input
  tax accounts; nothing reads the column. It is checked by
  `php artisan accounting:verify-tax-rates` rather than trusted, because a rate that
  names the wrong account is worse than one that names none. Honouring it per rate
  would need `tax_rate_id` on nine document item tables.
- **No tax jurisdiction rules** beyond the single effective-dated rate catalogue.
  No VAT/GST registration or filing workflow; returns are computed from the ledger but
  there is no authority-specific export format.
- **Historical foreign-currency rows** were backfilled at face value (rate 1). The
  migration documents this rather than correcting it.
- **Never rendered in a browser.** Contrast is measured and asserted by
  `ContrastTest`, but no page has been looked at. The census suite proves every route
  does not crash; it does not prove any page is *correct*.
- **No CRM, helpdesk, e-commerce or multi-channel.** This is a single-company ERP,
  not an Odoo clone, and it is not intended to become one.

---

## Manufacturing

### Scrap and yield

Completion used to write `quantity_used = quantity_required` over every component
line with one bulk update, and receive the ordered quantity into stock. That is an
accounting problem, not a reporting one, in three places: stock written off during a
run was recorded as consumed in full and the loss vanished; a run producing 90 good
units out of 100 posted 100 into stock; and material that went into rejected output
had no home, so it stayed buried in the cost of the good units and quietly flattered
the margin on what did sell.

The two numbers the shop floor can actually report are now recorded —
`quantity_good` and `quantity_scrap`, plus per-component `quantity_scrapped` with a
reason — because "12 units of component 4 were scrapped" and "12 units were rejected
at final inspection" are different problems needing different fixes.

**Scrap is absorbed into the good units, not expensed.** The material really was
consumed to make them, and a run rejecting 10 of 100 produces 90 units that cost what
the whole batch cost. Expensing the scrap would credit the good units with a cost
they were never charged, understating inventory and overstating margin. Unit cost is
therefore taken over good **and** scrap:

```
1,000 components at 10 = 10,000, over 100 units produced = 100 each
90 good units are carried at 9,000, absorbing the 1,000 the scrap consumed
```

What keeps it visible is **Production Variance** (6700), which measures real
production cost against standard cost. Reporting is entirely optional and optional
per field: an order completed without it behaves exactly as before.

> **No input screen yet.** The columns, the service, the endpoint and the display
> all work and are tested, but there is no form for reporting them — the values must
> be POSTed to the status endpoint by hand. See
> [Not reachable from the interface](#not-reachable-from-the-interface).

Covered by `ProductionYieldTest` (9).

### Multi-level bills of materials

A BOM could already *express* a sub-assembly — a component line points at an
inventory item, and that item can be the subject of a BOM of its own — but nothing
read the second level. A product built from a sub-assembly planned to buy the
**sub-assembly**: an item no supplier sells, so the requirement could not become a
purchase order, the order could not be costed, and it could never be completed
against real stock.

`BomExplosionService` recurses to the purchasable leaves. Wastage applies at **every**
level, not only the top — a sub-assembly is built with its own loss, and ignoring that
under-orders whatever it is made of. MRP and the production order go through the same
service so they cannot disagree. A component reached by two paths is one line with
the requirement summed; two lines would double-count its availability.

**The cycle guard is the load-bearing part.** A BOM can contain itself by accident,
and A-requires-B-requires-A is one data-entry mistake. That is not an error to
return — it is an unbounded recursion that exhausts memory and takes the request
process with it. The chain of items being expanded is carried down and a repeat is
**reported**, not followed, because a broken recipe is a data error the owner must
fix and silently returning the branches that happened to work would let it look fine.
A cycle on one branch does not lose the branches that are healthy.

Covered by `MultiLevelBomTest` (11).

### Work centres and routings

There was no work centre, machine or equipment record anywhere in this application.
`ProductionAbsorptionService` absorbed overhead on direct labour hours and said why in
its own docblock. That limitation is now removed rather than documented around.

A **work centre** is a place work happens — a machine, a bench, a person — carrying
its own rate and available minutes per day. The type is stated rather than implied by
a name, because it changes what the rate *means*: a machine hour and a labour hour are
different units.

A **routing** is the sequence of operations a product goes through, attached to its
bill of materials rather than to the product, because the operations belong to the
recipe. Standard time is `setup + (run per unit × quantity)`: a 10-unit and a
1,000-unit batch take very different times on the same centre.

```bash
# GET /inventory/work-centres          — the centres
# GET /inventory/work-centres/capacity — committed minutes per day, worst first
```

An order owns a **copy** of the routing it was released against, so editing a BOM
cannot retime work already half done, and re-releasing overwrites only the planned
side of operations nobody has reported on. Reported minutes **accumulate** rather than
replace, because the first entry is the one most likely to exist on paper.

Capacity counts the greater of recorded and planned minutes for every operation due
that day, **including completed ones**. Excluding completed operations is the obvious
reading and is wrong: the minutes a finished job took are exactly what it consumed
from the centre. An operation nobody has reported on has *no* variance, which is
different from zero — reporting 0 minutes would claim it was infinitely quick.

> **No routing editor yet.** Work centres have a full CRUD interface, and a routing
> is released onto an order and reported on correctly — but there is no screen for
> *defining* a routing on a bill of materials. `inventory_bom_operations` can only be
> populated programmatically today; the BOM form still edits components only. See
> [Not reachable from the interface](#not-reachable-from-the-interface).

Covered by `WorkCentreRoutingTest` (14).

## Document System

### Shared PDF design

All nine document types (quote, order, delivery note, credit note, recurring invoice, purchase quote, purchase order, debit note, purchase invoice) plus the sales invoice render from a single design:

- **Left rail** -- full-height band in the brand primary colour (darkened by `darken_hex()`)
- **Header band** -- company logo, document title, number and dates
- **Bill-to / Bill-for block** -- resolved by `DocumentData::billToFor()` per model
- **Line table** -- boxed header row, alternating-row zebra, boxed total row
- **Totals** -- subtotal, discount, tax, grand total
- **Footer** -- notes, payment terms, signature block

View files:
- `resources/views/documents/pdf.blade.php` -- all documents except sales invoices
- `resources/views/sales/invoices/pdf.blade.php` -- sales invoices (same design)

### DocumentData payload

`App\Support\DocumentData::build(Model $model)` returns an array consumed by the PDF views. Supported models:

`SalesQuote`, `SalesOrder`, `SalesCreditNote`, `SalesDeliveryNote`, `SalesRecurringInvoice`, `PurchaseQuote`, `PurchaseOrder`, `DebitNote`, `PurchaseInvoice`

**`SalesInvoice` is deliberately not in this list.** It renders from `resources/views/sales/invoices/pdf.blade.php` via `InvoiceTemplate::view()`, which resolves every stored template name to the single shared design. Adding it to `DocumentData::TYPES` would give it a second, divergent PDF path. (An earlier version of this README listed it here; that was wrong.)

`DocumentData::TYPES` is the single place that maps a document class to its title, route base, party side, pricing flag and settlement flag. Each registered entry requires four routes to exist -- `.index`, `.edit`, `.pdf`, and a `.<action>` permission -- because `build()` resolves all of them. `DocumentShowPageTest` asserts the registry and the route table agree.

### Live preview

`resources/views/documents/show.blade.php` (and the invoice equivalent) render a browser-side preview using the same data as the PDF. The preview and the PDF share the same rail colour via the `darken_hex()` helper.

### Print stylesheet

`@media print` hides navigation, sidebar, and all action buttons (Email, Download, Print, Back). Printing an open document page produces exactly one page of document content.

### DomPDF constraints

- Use **percentages** for column widths -- `width` in mm is silently ignored on `<td>`.
- Use **`<th>`** for column widths, not `<colgroup>` -- DomPDF ignores `<colgroup>`.
- No **non-ASCII punctuation** -- em-dashes, curly quotes, and `&minus;` render as mojibake (`convert_entities` is on).
- No **`display: table-cell` divs** -- use real `<table>` for any multi-column band.
- No **Blade layouts or components** inside PDF templates -- DomPDF cannot resolve `@extends` or `<x-*>`.
- **Padding is added on top of a cell's declared width**, not inside it. In a fixed
  table layout a `width: 61%` cell carrying `padding-left: 6mm` is 61% *plus* 6mm,
  so the canvas column has to be cut back to leave room or the right-hand edge runs
  off the paper.
- **`@page` margin boxes do not render at `margin: 0`** -- which the full-bleed
  document rail requires. Page numbering therefore uses a `position: fixed` block
  with `content: counter(page)`, the same mechanism the rail relies on, and DomPDF
  re-emits it on every page. Do **not** write "Page N of M": `counter(pages)`
  resolves to 0 because the total is unknown during layout, so it prints "Page 3 of 0".
- **Streams are compressed and fonts are Identity-H CID subsets**, so grepping a
  rendered PDF for text does not work. `tests/Support/PdfText.php` decompresses the
  streams and reads the text back through the ToUnicode CMaps.

### Printable POS receipts and payslips

| Document | Route | Notes |
|---|---|---|
| POS receipt | `GET /pos/receipts/{sale}/print` | **80mm thermal roll by default**, `?size=a4` for the filed copy |
| Payslip | `GET /employees/payroll/{run}/payslips/{payslip}` | Bind through its run, so a payslip id from another run is a 404 |

POS receipts had no printable form at all — the only view was an HTML screen page.
The 80mm geometry is asserted in points (226.77pt), because passing `a4` to
`setPaper()` would render a perfectly readable receipt on the wrong roll and still
pass every content check.

Payslips itemise attendance and statutory withholding **separately**: statutory money
is withheld by law and never belonged to the employee, so presented as one
"deductions" figure it reads as a penalty. A run that is neither approved nor paid is
stamped on the document.

### Verifying layout visually

```bash
# Every document type, from whatever documents already exist in the database.
php preview_document_pdfs.php
php preview_document_pdfs.php companybased_rehearsal   # against another schema

# Deliberately short / medium / long documents, to exercise pagination.
php preview_invoice_layout.php

# Rasterise to PNG for inspection.
python render_invoice_pdf.py                                  # every preview-*.pdf
python render_invoice_pdf.py storage/app/preview-long.pdf     # one file
```

`pypdfium2` (pip-installable) rasterises the PDFs. Output lands in
`storage/app/`: `preview-*.pdf` plus a `_p1`, `_p2` PNG per page.

Pass a database name to render from a copy rather than your working data — these
scripts create and delete rows.

### What visual review found (2026-10-09)

Both of these were invisible to the test suite, which asserts PDF *bytes* are
produced, never that a page looks right. Rasterising the output is the only way
to catch them.

1. **The left rail clipped the canvas text on every page of every shared
   document.** "QUOTE" rendered as "UOTE", "Date" as "ate", "Issue date" as
   "ssue date" — the rail painted over the canvas column's first characters
   because the canvas cell had no left padding. Fixed by `padding-left: 6mm` on
   `td.canvas` plus cutting the canvas width from 69% to 61% so the padding does
   not push the right edge off the page.

2. **The totals block ran off the right edge**, clipping "180.00" to "180.0".
   It was a fixed `74mm` table plus a `50mm` gutter — 124mm, wider than the
   canvas cell once the rail and the new padding are taken off it. Both are now
   percentages (62% / 38%).

Neither defect appeared in a 3-line document, which is all the demo data has.
`preview_invoice_layout.php` exists so the multi-page path can be exercised on
demand.

### Deployment rehearsal (2026-10-09)

The upgrade path was rehearsed against a **copy of real data** rather than
assumed: the working `companybased` database was dumped, restored to a scratch
schema, rewound to its pre-engine state (`tax_rates` dropped, the engine
migrations unmarked), and `migrate --force` run forward.

Result: **upgrade safe.** All row counts preserved, legacy percentages
byte-identical, and every previously-blocked document became saveable.

One defect surfaced only because the rehearsal was done: rolling back
`2026_10_06_000003_add_currency_to_journal_entry_items` failed with
*"Cannot drop index 'journal_items_account_currency_index': needed in a foreign
key constraint"*. The composite index on `(account_id, currency)` had become the
only index supporting the `account_id` foreign key, because MySQL accepts a
leftmost-prefix match for it. The `down()` path now recreates a plain
`account_id` index first, then drops the composite one, then the columns — in
that order, because the reverse fails with the same error.

Rollbacks are not part of a normal `migrate --force` deployment, but an
unreversible migration is worth knowing about before you need it.

---

## Automated Test Suite

### Quick reference

```powershell
# Full suite
php vendor/bin/phpunit --no-coverage

# Single file
php vendor/bin/phpunit tests/Feature/MultiEmailSetupTest.php --no-coverage

# With coverage (slow)
php vendor/bin/phpunit
```

### Test database setup

```sql
CREATE DATABASE companybased_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Add to `.env`:

```
DB_TEST_DATABASE=companybased_test
```

`Tests\TestCase` refuses to run if `DB_TEST_DATABASE` equals `DB_DATABASE`.

### Running two suites at the same time

Every test class runs `migrate:fresh`, which drops and recreates **every table** in the connected schema. Two PHPUnit processes sharing `companybased_test` therefore destroy each other's database mid-run and report failures that look like application bugs (deadlocks, "table doesn't exist").

`phpunit.sweep.xml` is an isolated copy that migrates into its own schema (`companybased_test_`). It differs from `phpunit.xml` in exactly one place — the `DB_TEST_DATABASE` value — so keep the `force="true"` and the mirrored `<server>` block in both; see the comment in the file itself.

```sql
CREATE DATABASE companybased_test_ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```powershell
php vendor/bin/phpunit -c phpunit.sweep.xml --no-coverage
```

`Tests\TestCase::guardAgainstProductionDatabase()` accepts `companybased_test_` because it is on its allow-list of disposable schemas, and still refuses any schema equal to `DB_DATABASE`.

### The census layer (whole-application coverage)

Six test classes sweep the application from the outside in. They are the safety net for "a new screen was added and nothing tests it".

| Test file | What it proves |
|---|---|
| `Census/RouteSmokeTest` | Every GET route renders for the persona allowed to see it; every auth-protected route refuses a guest; every POST/PUT/PATCH/DELETE survives an empty payload without a 500. |
| `Census/RouteSecurityPostureTest` | Every route is either auth-protected or on a written list of intentionally public routes; every business route declares a permission or role gate. |
| `Census/ViewIntegrityTest` | Every Blade file compiles; every `view()`, `@include`, `route()` and `<x-...>` reference resolves; Blade directive pairs are balanced. |
| `ViewIntegrityTest` | Every `route()` name used in a Blade file is registered; every `can()` string and nav entry exists in the permission/route registry. |
| `Census/ModelContractTest` | Every model points at a table that exists; every fillable/hidden column exists in the schema; every business table has a model. |
| `Census/ControllerCoverageTest` | Every route resolves to a real public controller method; no controller is unreachable; every controller extends the base class. |

The sweeps pick their persona from the route's own `permission:` middleware rather than by trial: "try each persona until one is not refused" reports the *last* refusal as the verdict, which mislabels a route the Admin can in fact reach. Employee self-service routes (`employees/my-…`) instead require a persona with a linked `employees` row, because those controllers act on the profile rather than the user row.

Two support classes do the work:

- **`tests/Support/RecordFactory.php`** builds a valid row for *any* table from the live schema: NOT NULL columns get type-appropriate values (clipped to the column width, unique on unique indexes), foreign keys are satisfied by recursively creating the parent row, enums get their first allowed value, and Spatie's `guard_name` is pinned to `web`. It also honours models that override `getRouteKeyName()` (POS receipts bind on `receipt_number`, not `id`).
- **`tests/Support/RouteSweep.php`** holds the route inventory, the prefix-aware `{param}` to table map, and every skip with its written reason.

Skips are declarations, not escape hatches: each carries a reason, and `test_the_sweep_skip_lists_are_documented` fails if a reason is missing or too short to audit.

### Domain, regression and volume tests

| Test file | What it proves |
|---|---|
| `Domain/CalculationConsistencyTest` | One figure computed two ways must agree (document line maths vs stored totals, dashboard cards vs invoice balances, dashboard receivables/cash vs the ledger, POS arithmetic, status vs balance). |
| `Domain/MoneyAccessorTest` | The money accessors behind every figure: `balance()`, `isPaid()`, cent-level rounding, exchange-rate lookup and base-currency conversion. |
| `Regression/KnownIssueRegressionTest` | One pin per documented Known Issue, asserting the CORRECT behaviour. Unresolved defects stop the test with their README id instead of failing the suite. |
| `NavBuilderTest` | Sidebar visibility and active-state: module gating, permission gating, `employee_only`, empty groups collapsing, `.index` standing for its section, and every nav route/permission string resolving. |
| `Stress/BulkDataStressTest` | Correctness at volume: 60-invoice balances, 40 consecutive document numbers, pagination that never repeats a row, dashboard and user admin with a full dataset. |
| `Auth/AuthFlowTest` | Login, logout, lockout after five failures, inactive/expired accounts, profile password change, admin user creation with roles. |
| `Api/ApiEndpointsTest` | The two `auth:sanctum` routes the web sweep skips. |
| `Views/DesignSystemLintTest` | No new legacy Breeze utility classes; the recorded baseline cannot go stale; both layouts apply dark mode before paint. |

### Test files

Test files added most recently, plus the ones most worth knowing about:

| Test file | Tests | What it covers |
|---|---|---|
| `YearEndCloseTest` | 7 | Retained earnings is a real figure, not a plug; close idempotency; unrelated accounts must not block it |
| `WeightedAverageValuationTest` | 11 | Blend by quantity, a sale does not move the average, value conserved, 4-decimal unit costs |
| `TaxReturnCalculatorTest` | 11 | Posted-only, credit note reduces the period, draft returns recomputable, filed ones refused |
| `ProductionYieldTest` | 9 | Only good output received, scrap absorbed, actual consumption recorded, valuation of output |
| `MultiLevelBomTest` | 11 | Sub-assemblies expanded, **cycle guard**, wastage at every level, shared components summed |
| `WorkCentreRoutingTest` | 14 | Recorded time survives re-release, minutes accumulate, capacity and overloading |
| `ContrastTest` | 11 | WCAG AA on every token pair; series colours at 3:1; no status colour without a `dark:` variant |
| `DocumentPageNumberTest` | 6 | Page number on **every** page; the PDF text extractor itself |
| `PosReceiptPrintTest` | 9 | 80mm geometry asserted in points; A4 variant; change-due box |
| `PayslipPdfTest` | 9 | Deductions itemised separately; figures reconcile; filed runs stamped |
| `InventoryLedgerTest` | 12 | On-hand == sum of movements, negative-stock rejection, decimal drift |
| `MailSettingsSecurityTest` | 14 | PUT/GET /settings/mail -- whitespace passwords, blank-box preservation, secret non-rendering |
| `MailTransportSchemeTest` | 7 | Transport scheme normalisation (ssl->smtps, tls->smtp, blank->smtp) |
| `MultiEmailSetupTest` | 7 | Accounts/Sales identities, credential validation, send-email routes |
| `MailCredentialsFormTest` | -- | Form round-trips for department mail credentials |
| `SecondMailboxLoginTest` | -- | Team mailbox credential storage and transport registration |
| `EmployeeOnlyAttendanceTest` | 9 | `User::isAttendanceTracked()`, attendance/leave route guards |
| `CompanySettingsAndVisitsTest` | 4 | PUT /settings/company, visit GPS start validation |
| `PosSaleStockTest` | 6 | Till sales decrement stock, overselling refused, line totals correct |
| `TransferStatusTest` | 3 | Stock move and status commit together |
| `LowStockAlertTest` | 7 | Edge-triggered alerting, restock re-arms it |
| `NotificationRenderingTest` | 3 | Mail bodies render without fatal errors |
| `InvoicePaymentTest` | 12 | Payment locks, ownership, currency, overpayment, status transitions |
| `RoleScopingTest` | 7 | Config-backed role helpers; Salesman scoped to own records |
| `StatusBadgeTest` | 8 | Badge colours and labels per module |
| `PayrollServiceTest` | 16 | Gross, deductions, payslip self-consistency, regeneration idempotence |
| `MiddlewareAccessTest` | 12 | CheckSubscription / CheckModule middleware |
| `PosShiftTest` | 10 | One shift at a time, variance, cancelled sales excluded |
| `DateBoundaryTest` | 3 | Inclusive date ranges; MySQL/SQLite date cast divergence |
| `GeneralLedgerTest` | 12 | Double-entry integrity: unbalanced journals refused, post/void transitions |
| `BankLedgerTest` | 8 | Atomic transfers, reversal removes both legs |
| `DocumentItemsTest` | 13 | Line maths, atomicity, validation bounds |
| `CustomerCreditTest` | 10 | Overpayment held as credit, auto-applied to next invoice |
| `CreditNoteSettlementTest` | 6 | applied_amount written and consumed correctly |
| `CustomerOpeningBalanceTest` | -- | Opening balance creates correct ledger entries |
| `PaymentReachesBankLedgerTest` | -- | Payments post to the bank ledger |
| `ManualDocumentNumberTest` | -- | Manual document numbers validated and persisted |
| `InvoicePdfTemplateTest` | -- | PDF bytes are produced for each document type |
| `DocumentPreviewTest` | -- | Preview routes return 200 and the expected content shape |
| `DatabaseBackupTest` | 18 | Dump/restore, streaming, safety backup, keep-count pruning |
| `DocumentShowPageTest` | 8 | Every document type's show page renders; every `route()` name in the shared view resolves |
| `ViewIntegrityTest` | 4 | No undefined route name or permission string in any of the 351 views or in `config/nav.php` |
| `DiscountLimitTest` | 12 | Over-limit discount redirects with a visible error instead of a raw JSON 422 |
| `WiredFeatureTest` | 21 | The 16 previously unreachable routes are reachable, and guard-blocked deletes are withheld |
| `SubscriptionAccessMemoTest` | 8 | The memo avoids the query; activation and deactivation take effect immediately |
| `ConvertCurrencyTest` | 11 | Converted documents reconcile: header, lines and payments |
| `TaxRateAdoptionMigrationTest` | 14 | Upgrading a deployment already trading: legacy tax percentages are adopted and dated so old documents stay saveable |
| `CashFlowAggregationTest` | 6 | SQL totals equal the model `balance()` they replaced |
| `FinancialReportAggregationTest` | 7 | Statements unchanged after moving to grouped queries; draft entries still excluded |
| `DashboardLayoutEditorTest` | -- | Dashboard customization routes |
| `AccountingAuditRegressionTest` | -- | Audit field integrity on accounting records |
| `AttendanceLocationAndQrTest` | -- | QR attendance flow and GPS guard |
| `OfficeQrCodeSettingsTest` | -- | QR settings persistence |
| `TestEnvironmentGuardTest` | -- | Guard refuses to run against the production schema |
| `Census/RouteSmokeTest` | 4 | GET sweep over all ~350 page routes, guest sweep over every auth-protected route, empty-payload sweep over ~250 write routes, skip-list audit |
| `Census/RouteSecurityPostureTest` | 3 | Every route is auth-protected or documented public; every business route carries a permission or role gate |
| `Census/ViewIntegrityTest` | 6 | Every Blade compiles; every `view()`, `@include`, `route()` and `<x-...>` resolves; directive pairs balanced |
| `Census/ModelContractTest` | 4 x 97 | Per model: table exists, fillable and hidden columns exist, query compiles; plus table coverage |
| `Census/ControllerCoverageTest` | 4 | Route actions resolve, no controller is unreachable, base-class discipline |
| `Auth/AuthFlowTest` | 14 | Login, logout, lockout, inactive/expired accounts, profile password change, admin user creation, registration reachability |
| `Api/ApiEndpointsTest` | 6 | The two Sanctum endpoints with a real bearer token |
| `Domain/CalculationConsistencyTest` | 7 | Line maths, invoice balance, dashboard cards vs ledger, POS arithmetic, status vs balance |
| `Domain/MoneyAccessorTest` | 8 | `balance()`, `isPaid()`, rounding, exchange rates, base-currency conversion |
| `Regression/KnownIssueRegressionTest` | 8 | One pin per documented Known Issue, asserting the correct behaviour |
| `Stress/BulkDataStressTest` | 5 | 60-invoice balances settled through real payments, 40 consecutive document numbers, pagination without repeats, full-dataset screens |
| `Views/DesignSystemLintTest` | 3 | No new legacy Breeze classes; the baseline cannot go stale; dark mode applied before paint |

### Why the suite is slow

`SeedsDatabase::setUp()` calls `$this->seed(DatabaseSeeder::class)` before every test. `PermissionsSeeder` and `RolesSeeder` are now bulk inserts, but `UsersSeeder` still creates 7 users with `Hash::make` (intentionally slow). Budget 12-14 minutes for a full run.

### Metadata lock hang

If a run appears to hang at 0% CPU:

```sql
SHOW FULL PROCESSLIST;
-- Kill any row in "Waiting for table metadata lock" against companybased_test
KILL <id>;
```

The most common cause is overriding `database.default` or the active connection's database name inside a test. **Never do this.** See `BackupController::connectionFor()` and `DatabaseBackupTest` for the correct pattern.

---

## Known Issues

Severity key: **CRITICAL** = breaks existing functionality right now. **HIGH** = breaks at real data volume or in a live deployment. **MEDIUM** = professionalism / maintainability gap. **LOW** = accepted limitation or low-risk debt.

Each issue includes the exact fix so whoever picks it up can act immediately.

### Status verification (2026-10-09, full re-run of the suite)

Each issue below was re-checked against the working tree by reading the named
lines and by a full suite run. Nothing here is taken on trust. Five rows that were
previously marked OPEN were found already fixed in code, and four have been
re-verified and closed since; the stale statuses were the reason this section was
rewritten rather than appended to.

| Issue | Status | Evidence |
|---|---|---|
| CRITICAL-1 Super Admin 403 on send-email | **FIXED (by decision)** | Deliberate. Super Admin holds only `settings.*` + `dashboard.overview.view`, and the send-email routes are gated on business view permissions. `MultiEmailSetupTest::test_super_admin_cannot_send_business_documents()` asserts the 403 so it cannot be "fixed" by accident. Full rationale below. |
| CRITICAL-2 Email button missing on quote/PO | **FIXED** | `resources/views/documents/show.blade.php:9` renders the button; quotes and purchase orders both render that shared view. Pinned by `Regression/KnownIssueRegressionTest::test_quote_and_purchase_order_screens_offer_the_email_action`. |
| CRITICAL-3 `DiscountLimit` returns JSON | **FIXED** | `app/Support/DiscountLimit.php:69` throws `ValidationException::withMessages(['items' => …])`. Pinned by `Regression/KnownIssueRegressionTest::test_exceeding_the_maximum_discount_returns_a_validation_error`, which also proves a discount *within* the limit still saves. |
| HIGH-1 `Subscription::isAccessBlocked()` uncached | **FIXED** | `app/Models/Subscription.php:22` declares `private static ?bool $accessBlockedMemo`, returned at `:84-86` and assigned at `:90`, with an explicit `flushCache()` at `:50-53` for when the subscription is written mid-request. |
| HIGH-2 CashFlow loads whole payment history | **FIXED** | `CashFlowController.php:266-291` and `:334-359` aggregate in SQL with `COALESCE(SUM(CASE WHEN ...))` + `groupBy('bank_account_id')`; `:306-328` are single `SUM()` lookups. No `->get()->sum(...)` remains. |
| HIGH-3 `ConvertCurrency` inconsistent | **FIXED** | `ConvertCurrency.php:181-195` converts line items first; `:198` derives the header via `DocumentItems::totalsFromPersistedItems()`; `:211-223` converts payments and recomputes `paid_amount` from them in the same transaction; `:233-243` uses `bcmul`. Dry-run preview unless `--force` (`:94-100`). Pinned by `ConvertCurrencyTest` (11). |
| MEDIUM-1 Accounting is manual-entry only | **FIXED (superseded)** | `LedgerPosting` now calls `GeneralLedger` from 4 sites (`LedgerPosting.php:233,238,565,911`) across every document flow, not only `JournalController`. The [Accounting](#accounting) section documents the resulting postings. |
| MEDIUM-2 Float money arithmetic | **FIXED** | `app/Support/Money.php` is a bcmath value object (149 lines) with 110 call sites across 12 files, including `LedgerPosting` (51) and `ProductionPostingService` (11). Both `DocumentData` blocks now delegate to `DocumentItems::lineTotals()` — the header at `:167-174` and the printed rows at `:237-245` — so the PDF cannot drift from the stored totals. |
| MEDIUM-3 Reports aggregate in PHP | **FIXED** | `FinancialReportController` uses grouped SQL for the chart (`:87-99`), positions (`:190-206`) and the P&L summary (`:159-193`); `DashboardService.php:104-143` pushes every figure to `->sum()`. The only remaining hydration is the general-ledger listing at `:24`/`:34`, which genuinely needs individual rows. |
| MEDIUM-4 Authorization duplicated | **FIXED** | `Controller::authorizePermission()` is called at 18 sites, and the sidebar's 59-line `@php` block is now `App\Support\NavBuilder` (`app/Support/NavBuilder.php`) — one testable implementation instead of a fourth copy of the rule. Pinned by `NavBuilderTest` (11), which also asserts every nav route and permission string resolves. |
| MEDIUM-5 `recalculateStatus()` indentation | **FIXED** | `Sales/InvoiceController.php:439` is correctly indented inside the class. |
| LOW-1 Receipt PDFs are separate designs | **FIXED** | All three controllers render `App\Support\ReceiptDocument` (`Sales/PaymentController.php:437`, `Purchasing/SupplierPaymentController.php:279`, `Sales/WithholdingTaxReceiptController.php:112`), which resolves to the single `partials/pdf/receipt.blade.php`. The two old per-template views are deleted. |
| LOW-2 Page 2+ has no left rail | Accepted limitation (fix attempted and rejected) | Verified by rendering a 48-line quote: page 2 repeats the boxed table header and carries the footer with company identity and the signature block, but not the rail. The rail is one 296mm table cell, and DomPDF cannot repeat a table row across a page break. A `position: fixed` band *was* tried and **rejected**: rather than adding a rail to page 2, it made DomPDF re-emit the whole page-1 rail (logo, tagline, bill-to, "thank you") on every continuation page and pushed a 48-line document from 2 pages to 3. Worse than the limitation it was meant to remove. A real fix needs the rail restructured out of the table entirely, which is a redesign of the shared template, not a patch. |
| LOW-3 Architectural debt | **OPEN (accepted)** | 1 factory for 101 models in `app/Models`; `SearchRegistry` wildcard `LIKE` untouched. The `no Money value object` clause that used to sit here is obsolete — see MEDIUM-2. |

Nothing in this table is a functional defect. The remaining OPEN row is LOW-3,
which is test-fixture ergonomics and search UX rather than a correctness problem,
and LOW-2 is a deliberate trade-off.

---

### ~~CRITICAL-1. `MultiEmailSetupTest` 403 on the send-email routes~~ -- FIXED

**The original diagnosis was incomplete.** It attributed the 403 to `RolesSeeder` giving Super Admin only `settings.*` permissions, which is true, but fixing only that is not enough: the replacement Admin actor is then stopped by `CheckSubscription`, which returns the `errors.subscription-expired` view with a **403** for any non-Super-Admin when no active package exists. `MultiEmailSetupTest` did not use the `SeedsDatabase` trait and so never created one.

Both are required:
1. Act as **Admin**, not Super Admin. Super Admin is by design an installation-configuration role and deliberately holds no business-module permissions.
2. Create an active `Subscription` in `setUp()`, as `SeedsDatabase` and `DocumentPreviewTest` already do.

The original entry's alternative fix -- adding `sales.invoices.view`, `sales.quotes.view` and `suppliers.purchase_orders.view` to the `$superAdmin` array in `RolesSeeder.php:85` -- is **not** recommended. It would quietly make Super Admin an operator on every sales and purchasing screen. `MultiEmailSetupTest::test_super_admin_cannot_send_business_documents()` now asserts the 403, so the decision is pinned and cannot be "fixed" by accident.

---

### ~~CRITICAL-2. Quote and Purchase Order show pages return 500~~ -- FIXED

**This entry was wrong when first written.** It claimed the Email button and modal were missing from the quote and purchase-order show pages, and proposed adding them to `resources/views/sales/quotes/show.blade.php` and `resources/views/suppliers/purchase_orders/show.blade.php`. **Neither file exists and never will:** every document type registered in `DocumentData::TYPES` renders through the single shared `resources/views/documents/show.blade.php`, and `QuoteController::show()` / `PurchaseOrderController::show()` both return that view. Following the original fix would have created two orphan Blade files and changed nothing.

The Email button was already present, in the shared view. What was actually wrong:

```php
// documents/show.blade.php -- both names are module-prefixed in routes/web.php
$sendRoute = $isQuote ? route('quotes.send_email', $doc)
                     : route('purchase_orders.send_email', $doc);
```

`route()` throws `RouteNotFoundException` on an unknown name, and this line sits inside the branch that runs for exactly those two documents, so `GET /sales/quotes/{quote}` and `GET /suppliers/purchase-orders/{order}` both returned 500.

**Fix applied:** use `route('sales.quotes.send_email', $doc)` and `route('suppliers.purchase_orders.send_email', $doc)`.

**Why it survived:** no test ever requested a show page. `DocumentPreviewTest` covers only the `create` screens and `InvoicePdfTemplateTest` uses the `pdf` endpoint, which builds its URL from `DocumentData` instead. `DocumentShowPageTest` now requests every document type's show page and asserts that every `route()` name in the shared view resolves.

---

### ~~CRITICAL-3. `DiscountLimit::validateDiscountLimits()` returns raw JSON on HTML form posts~~ -- FIXED

**Was:** `app/Support/DiscountLimit.php` called `abort(response()->json([...], 422))` from a
browser form post, so the user received a JSON blob with no way back.

**Now:** `DiscountLimit::validateDiscountLimits()` (`:69`) throws
`ValidationException::withMessages(['items' => $message])`. The message is written under
both the aggregate `items` key and a dotted per-line key, and the comment records why:
Laravel's `has()` does not match a dotted child against a flat bag, so the aggregate key
is the one the line-item editor actually renders.

**Pinned by** `Regression/KnownIssueRegressionTest`:

- `test_exceeding_the_maximum_discount_returns_a_validation_error` - with an active
  `DiscountRule` capping the discount at 5%, a 50%-discount invoice redirects with errors
  under `items` and is **not** persisted.
- `test_a_discount_within_the_limit_is_accepted` - the guard is not a blanket block: a
  5%-discount invoice under a 10% rule saves with the expected total of 95.00.

------

### CRITICAL-4. The whole `/api` surface is unusable — `User` has no `HasApiTokens`

**Status:** open, found by `Feature/Api/ApiEndpointsTest`.

**Root cause.** `routes/api.php` guards both endpoints with `auth:sanctum`:

```php
Route::middleware('auth:sanctum')->get('/user', [UserController::class, '__invoke']);
Route::middleware(['auth:sanctum', 'module:employees', 'permission:employees.attendance.mark'])
    ->post('/attendance/scan', [AttendanceApiController::class, 'scan']);
```

but `app/Models/User.php:24` declares:

```php
use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes;
```

`Laravel\Sanctum\HasApiTokens` is missing. Sanctum's `Guard` resolves a token by loading the tokenable and then calling `withAccessToken()` on it; with the trait absent that call lands on `Model::__call()` and throws:

```
BadMethodCallException: Call to undefined method App\Models\User::withAccessToken()
```

so **every** token-authenticated request to `/api/*` answers **500**. There is also no route, screen, console command or seeder anywhere in the app that calls `createToken()`, so no client can obtain a token in the first place — the attendance-scan endpoint, which is the only feature the API exists for, has no reachable caller.

**Fix.** Add the trait and give it something to manage:

```php
use Laravel\Sanctum\HasApiTokens;

// app/Models/User.php
use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;
```

and add an issuance route (a Super-Admin-only screen under `settings/users` listing each user's tokens with revoke, plus `POST /settings/users/{user}/tokens`). Personal access tokens are stored in `personal_access_tokens`, which already exists in the schema — no migration needed. Until the trait is added, delete `routes/api.php` and its controllers rather than shipping a 500-ing public contract.

**Regression pin:** `Feature/Api/ApiEndpointsTest` writes a real `personal_access_tokens` row and calls `/api/user` with the bearer token. It skips with `KNOWN BUG [CRITICAL-4]` while the defect is live and turns green by itself once the trait is added.

---

### ~~HIGH-1. `Subscription::isAccessBlocked()` runs an uncached SELECT on every page load~~ -- FIXED

**Root cause:** `CheckSubscription` middleware calls `Subscription::isAccessBlocked()` on every authenticated request. The method does `Subscription::active()->latest()->first()` — two DB round trips, no cache. `Setting` and `Module` both memoize; this doesn't.

**Fix** — add a static memo to `Subscription::isAccessBlocked()`:

```php
// app/Models/Subscription.php
private static ?bool $accessBlockedMemo = null;

public static function isAccessBlocked(): bool
{
    if (self::$accessBlockedMemo !== null) {
        return self::$accessBlockedMemo;
    }

    $sub = static::current();
    return self::$accessBlockedMemo = (
        $sub === null || $sub->expires_at === null || $sub->expires_at->isPast()
    );
}

// Call this in tests setUp() if you change subscription state between cases:
public static function flushAccessCache(): void
{
    self::$accessBlockedMemo = null;
}
```

---

### ~~HIGH-2. `CashFlowController` loads entire payment history into PHP memory~~ -- FIXED

**Root cause:** `inflowRows()` and `outflowRows()` call `->get()` on `SalesPayment` and `SupplierPayment` with no date constraint on what is loaded for the running balance totals. On shared hosting (128 MB PHP limit) this OOMs at a few thousand payment rows.

**Fix:** Aggregate running totals in SQL. Replace the PHP `->sum()` calls with `DB::table(...)->whereBetween(...)->sum('amount')`. Only the activity feed (last 12 rows) and the 30-day chart need the full row data, and those are already range-bounded.

```php
// Instead of:
$receivables = SalesInvoice::where('status', '!=', 'paid')->get()->sum('balance');

// Use:
$receivables = SalesInvoice::where('status', '!=', 'paid')->sum(DB::raw('total - paid_amount'));
```

---

### ~~HIGH-3. `ConvertCurrency` console command is untested and produces inconsistent documents~~ -- FIXED

**Root cause:** `app/Console/Commands/ConvertCurrency.php` rounds `subtotal`, `tax_amount`, and `total` independently after multiplying by the exchange rate. After conversion `subtotal + tax_amount !== total`. It also never converts line-item prices, so the printed lines no longer sum to the header total. The command is available to all Admin users in production.

**Fix in priority order:**
1. Disable the command from `Console/Kernel.php` or add a `--dry-run` gate until fixed.
2. Convert line items first, call `DocumentItems::sync()` to recompute totals from lines, and let the service apply its existing rounding rules — do not touch `subtotal`/`tax`/`total` directly.
3. Add a `ConvertCurrencyTest` that asserts `subtotal + tax_amount === total` and that each line item's `unit_price` is converted.

---

### ~~HIGH-4. "Deactivate package" locked every user out and told the admin the opposite~~ -- FIXED

**Status: fixed.** Verified 2026-10-09 — the misleading copy no longer exists in
either file, and the pin passes.

**The original defect:** `SubscriptionController::deactivate()` sets `is_active = false`, so `Subscription::current()` returns `null`, and `Subscription::isAccessBlocked()` returns `true` when there is no active package. Every non-Super-Admin was therefore refused by `CheckSubscription` with a 403 on every page — while both the confirmation dialog and the success flash explicitly promised the opposite:

| Location | Text that used to say | Actual effect |
|---|---|---|
| `resources/views/settings/subscription.blade.php` | "Deactivate the package? All users will regain access immediately." | Locks every staff user out |
| `Settings/SubscriptionController.php` | "Package deactivated. All users now have unrestricted access." | Access is now **blocked** |

**Why it mattered:** one click, behind a dialog that promised the opposite, took
the whole company offline with no self-service recovery.

**The fix took the "deactivating should block" reading** and made the copy say so.
The flash now reads:

> Package deactivated. Because no active package remains, staff access is now
> BLOCKED until a package is activated again.

A comment above it records why, so the next person does not "correct" the wording
back into a lie. Verified absent: neither `regain access` nor
`unrestricted access` appears in either file.

**Regression pin:** `Regression/KnownIssueRegressionTest::test_deactivating_the_package_does_not_claim_staff_keep_access`
passes. It only skips when staff are blocked **and** the copy claims otherwise, so
it guards the pairing — either half changing alone will not silently pass.

---

### ~~MEDIUM-1. Accounting is manual-entry only~~ -- FIXED (2026-10-08)

The original finding read: *"No code path generates journal entries automatically.
`GeneralLedger` is invoked only from `JournalController`. Sales, purchasing, banking,
inventory, and payroll never post to the ledger. Chart of accounts is not seeded."*

Every clause of that is now false. The decision it asked for -- auto-generate
journals, Odoo-style -- has been taken and implemented:

- `LedgerPosting` raises a balanced, idempotent, immediately-posted journal for each
  business event. See the full event table in [Accounting](#accounting).
- `ChartOfAccountsSeeder` seeds the chart, and is called by `DatabaseSeeder`.
- Sales, purchasing, banking, inventory, POS and payroll all post.
- Corrections void and repost rather than editing a journal in place, so the mistake
  and its fix both stay on the audit trail.

The remaining ambiguity this issue flagged -- whether a deployment should expect an
accountant to post by hand -- is resolved: it should not. See
[Still missing](#still-missing) for what genuinely does not post yet.

---

### ~~MEDIUM-2. Money arithmetic uses `round()` on doubles, not bcmath~~ -- FIXED

Storage is safe (187 `decimal()` columns, all cast to `decimal:2`). The arithmetic layer is not:

- `ConvertCurrency.php` — see HIGH-3 above.
- `DocumentData.php:191-199` — recomputes document totals in float for PDF rendering using a different formula from `DocumentItems::sync()`. A PDF can disagree with the stored total by a cent.
- `PayrollService.php:119-124` — multiplies money by an integer and rounds only the final sum.

`ext-bcmath` is declared in `composer.json` but unused in `app/`. **Fix:** Introduce a thin `Money` value object backed by `bcmul`/`bcadd`/`bcdiv` and replace the three arithmetic sites above.

---

### ~~MEDIUM-3. Report controllers aggregate in PHP~~ -- FIXED

- `FinancialReportController.php:145-151` — hydrates every posted journal line since inception, then re-queries the same period two more times for chart data.
- `CashFlowController.php` — loads all open invoices and all bank accounts into memory.

**Fix:** Move aggregation into SQL `GROUP BY` queries. The controller should receive pre-summed numbers, not collections to `->sum()` in PHP.

---

### ~~MEDIUM-4. Authorization logic is duplicated in three places~~ -- PARTIALLY WRONG

Route middleware, `sidebar.blade.php`, and `VisitsController::authorizeVisitAccess` each enforce the same permission and module-enabled rules independently, and they will drift.

**But the claim that `Controller::authorizePermission()` "is defined and never called from anywhere" is false.** It has 20 call sites across 8 controllers, including `HolidayController` and every other accounting, banking and employee controller. It is the *preferred* form; the `abort_if(! auth()->user()?->isSuperAdmin(), 403)` calls in `SubscriptionController` and the bare `->can()` checks in Blade are the outliers.

**Still worth doing:** extract a `NavBuilder` service so `config/nav.php` and the sidebar render from one permission registry, and replace the remaining bare `abort_if` calls with `$this->authorizePermission()`.

---

### ~~MEDIUM-5. `InvoiceController::recalculateStatus()` has wrong indentation~~ -- FIXED, but severity overstated

The method was declared at column 0 while the rest of the class is indented four spaces.

**This was never a MEDIUM.** A method at column 0 inside a class body is valid PHP; it parses and executes identically. It is a cosmetic defect that reads as though the method sits outside the class. Re-rate it LOW. Fixed.

---

### ~~LOW-1. Receipt PDFs are separate designs~~ -- FIXED

**Status: fixed.** All receipt types now render from one shared template.

The original finding listed `sales/payments/pdf`, `suppliers/supplier_payments/pdf`
and `sales/withholding_tax_receipts/pdf` as unmigrated. They no longer exist as
separate designs. Every receipt controller resolves through
`App\Support\ReceiptDocument` to the single
`resources/views/partials/pdf/receipt.blade.php`:

| Controller | Line | Renders |
|---|---|---|
| `Sales\PaymentController::pdf()` | `:437` | `ReceiptDocument::salesPayment($payment)->render()` |
| `Purchasing\SupplierPaymentController::pdf()` | `:279` | `ReceiptDocument::supplierPayment($payment)->render()` |
| `Sales\WithholdingTaxReceiptController::pdf()` | `:112` | `ReceiptDocument::withholdingTaxReceipt($receipt)->render()` |

Verified visually by rendering a receipt and inspecting the output: one design,
brand-consistent, with the amount block and reference details all legible.

**`exports/pdf` is a separate case and is still open** — it is a tabular export
rather than a document, so it legitimately has its own layout.

---

### ~~LOW-2 — page 2+ of a long document has no left rail~~ — FIXED

**Status: fixed.** The rail is now a `position: fixed` block outside the flow,
and the canvas is inset with a margin to clear it.

The rail used to be a single 296mm `<td>` beside the canvas. DomPDF cannot repeat
a table row across a page break, so it rendered on **page 1 only** and page 2 of a
long document came out as an anonymous sheet of numbers: no brand, no company
name, no bill-to. DomPDF *does* re-emit a fixed-position block on every page.

Verified by rendering a 48-line quote: all three pages now carry the rail — logo,
company name, tagline, bill-to, "Thank you" — and the totals still sit clear of
the right edge.

Two failures had to be cleared on the way, both of which had been invisible until
the pages were actually looked at:

1. **Duplication.** The first attempt added a fixed band but *left the rail cell
   in place*. DomPDF then re-emitted the entire page-1 layout on every
   continuation page and pushed the 48-line document from 2 pages to 3 — worse
   than the limitation. The rail has to leave the table, not be duplicated
   beside it.

2. **Overlap.** DomPDF adds an element's padding *outside* its declared width and
   ignores `box-sizing: border-box` when it does. At `width: 28.5%` with 10mm of
   padding the painted band reached 33.3% of the page against a 33% canvas inset,
   so the rail covered the first character of every heading — "QUOTE" rendered as
   "Q U O T E", "Date" as "ate". The width is now 23.5%, which leaves the painted
   band at ~28.3%.

Pinned by `DocumentRailTest` (5), which asserts the rail is fixed, is not back in
a table cell, and that its width leaves room for its padding. It cannot prove the
rendering — that needs a rasterised page — so `preview_document_pdfs.php` plus
`render_invoice_pdf.py` remains the visual check.

**Page numbers are still absent.** `@page { @bottom-center }` does not render
while the page margin is `0`, which the full-bleed rail requires. The rail carrying
the company identity on every page is the better trade, but it is not a
substitute for "page 2 of 3" on a legal or tax document.

---

### LOW-3. Architectural debt items (not blocking, but compounding)

| Item | File / location | Suggested fix |
|---|---|---|
| 94 of 109 controllers bypass the service layer | All CRUD controllers | Extract one service at a time, starting with the highest-traffic ones (Invoice, Payment) |
| Validation inline in controllers | `app/Http/Requests` has 2 files for 607 routes | Create `FormRequest` classes per resource |
| Dependency cycles | `InventoryLedger <-> LowStockService`, `Setting <-> Branding`, `NotificationService <-> Notifications` | Introduce a mediator or event for the cycle |
| Business logic in Blade | `suppliers/index.blade.php`, `sales/customers/index.blade.php`, `public/track.blade.php` | Move to model accessors or view-models |
| 97 of 98 models have no factory | All models | Add factories starting with the most-tested models |
---

## Authentication & Access Model

This is the intended shape of the login system. It is written down here so the
suite can assert it and so nobody re-adds a flow that was removed on purpose.

| Concern | Decision | Where it lives today |
|---|---|---|
| Who creates accounts | **An administrator**, from `Settings -> Users`, where they also assign roles. | `Settings\UserController::store()` |
| Self-registration | **Removed.** No `register` route, controller, blade or config flag exists — there is no switch that could be turned back on. | `routes/auth.php` |
| Forgotten passwords | **No self-service e-mail reset.** An admin resets the password while editing the user. | `Settings\UserController::update()` |
| Password changes | **The signed-in user, from their profile.** Requires the current password. | `Auth\PasswordController::update()` |
| Session lifetime | 120 minutes; "remember me" issues a long-lived cookie. | `config/session.php` |
| Lockout | 5 failed attempts per e-mail + IP, then a timed refusal. | `Auth\LoginRequest` |
| Inactive / expired accounts | Ejected by `EnsureUserIsActive` on every request. | `app/Http/Middleware/EnsureUserIsActive.php` |

### DORMANT CODE — present, unlinked, tracked as AUTH-1/AUTH-3

The e-mail password-reset flow still ships (routes, controllers, views) but is
unreachable from the interface. Until it is removed it is live attack surface
with no `throttle` middleware on it.

- `routes/auth.php:27-37` — `forgot-password` (GET+POST), `reset-password` (GET+POST)
- `app/Http/Controllers/Auth/PasswordResetLinkController.php`
- `app/Http/Controllers/Auth/NewPasswordController.php`
- `resources/views/auth/forgot-password.blade.php`, `reset-password.blade.php`

Also dormant: e-mail verification (`verify-email*` routes, views and
`VerifyEmailController`), because `User` does not implement `MustVerifyEmail`
(`app/Models/User.php:5` has the import commented out), so `verified` middleware
is a no-op.

**Removal checklist (when actioned):** delete the four files above; drop
`password.request` / `password.email` / `password.reset` / `password.store` from
`CheckSubscription::$excluded`; remove the "password resets" wording from
`resources/views/settings/mail.blade.php:46` and the `MailIdentity` docblock;
delete `AuthFlowTest::test_forgot_password_page_is_reachable_but_sends_no_reset_link`.

---
| 7 timestamp-duplicate migration pairs | `database/migrations/` | The tree now holds 124 migrations. Rename the duplicates before adding the next one |
| Leading-wildcard `LIKE '%term%'` in 26 models | `SearchRegistry.php` | Add a `FULLTEXT` index or adopt `Laravel Scout` |
| `money()` renders differently locally vs. production | `app/Support/helpers.php` | Install `ext-intl` locally or normalise the renderer |

---

---
---

## Bugs found by the census suite

Found by the automated sweeps above rather than by reading code. Each carries a
pinning test in `tests/Feature/Regression/KnownIssueRegressionTest.php` or
`tests/Feature/Domain/CalculationConsistencyTest.php` that asserts the CORRECT
behaviour and stops with its issue id while the defect is still present — so the
suite stays green, the defect stays documented, and the test turns green the day
the code is fixed.

### ~~AUTH-1 — "Access expires" fails open~~ — FIXED

`access_until` is in `#[Fillable]` (`app/Models/User.php:19`) and cast to `date`
(`:49`), so the value persists and the `->isPast()` / `?->format()` calls no
longer operate on a raw string.

`LoginRequest::authenticate()` (`app/Http/Requests/Auth/LoginRequest.php:53`) now
checks the account's own state **before** accepting credentials:

```php
$accountUsable = $user !== null
    && $user->is_active
    && ($user->access_until === null || ! $user->access_until->isPast());
```

Every rejection returns the same generic `auth.failed` message — distinguishing
"wrong password" from "account disabled" would tell an unauthenticated caller
which addresses are registered.

### ~~AUTH-2 — an inactive account completes a login~~ — FIXED

Closed by the same change as AUTH-1. The check moved from the *next* request
(`EnsureUserIsActive`, which let a disabled account hold a session for the
remainder of one request and bounce on the following one) to the login itself, so
an inactive or expired account is never issued a session at all.

Pinned by `Auth/AuthFlowTest` — 14 tests, all passing, no skips.

### ~~AUTH-3 — password-reset endpoints are unthrottled~~ — FIXED

**Status: fixed.** Both endpoints now carry `throttle:6,1` in `routes/auth.php`,
matching the verification routes already in that file.

`password.email` is the one that matters for abuse: with no limit an
unauthenticated caller controls how much mail the business sends, and can enumerate
accounts against it. The *response* is already identical either way — Laravel's
`PasswordBroker` deliberately does not reveal whether an address is registered —
so throttling the volume was the only missing control, not information disclosure.

`password.store` is throttled separately for a different reason: a leaked or
guessed reset token was brute-forceable at whatever rate the server allowed.

### PROD-1 — the role named "Super Admin" cannot do most of what "Admin" can

**Status:** working as designed; documented because it surprises everyone first.

`RolesSeeder` builds the Super Admin role from a curated allow-list
(`$superAdminSettings`, `database/seeders/RolesSeeder.php:379`) that covers
company profile, branding, modules, dashboards, base currency, backups, audit,
mail and the subscription — plus `employees.my_*` self-service. It deliberately
excludes `settings.users.*`, `settings.roles.*` and every business module.

The consequences a new install hits immediately:

| Account | Role | Can open `/settings/users` |
|---|---|---|
| `superadmin@nexosdigital.test` | Super Admin | **No — 403** |
| `admin@nexosdigital.test` | Admin | Yes |

The account an installer is handed after `db:seed` is the one that cannot manage
users, create roles, or open any sales, purchases, inventory or accounting
screen. The name is the problem: "Super Admin" reads as strictly above "Admin"
but is in fact a system-settings role with fewer capabilities.

Either rename the roles (`System Administrator` / `Administrator`) or widen the
Super Admin role to `all permissions minus nothing`, keeping `settings.roles.*`
itself reserved. **This is a product decision — it is not changed by the test
suite.** `Stress/BulkDataStressTest::test_the_super_admin_role_cannot_administer_users`
pins the current behaviour so it cannot drift silently, and
`test_dashboard_and_user_admin_render_with_a_full_dataset` drives the
user-administration screen as Admin.

### ~~PROD-2 — self-registration scaffolding shipped but unreachable~~ — FIXED

**Status: fixed — deleted, not disabled.** Accounts are created by an
administrator under `settings/users`; self-sign-up is not a feature of a
single-company install.

| Artefact | Disposition |
|---|---|
| `app/Http/Controllers/Auth/RegisteredUserController.php` | **Deleted** |
| `resources/views/auth/register.blade.php` | **Deleted** |
| `config('app.enable_registration')` | **Deleted** |

The route previously sat behind `config('app.enable_registration')`, defaulting to
`false`. That looked safe and was not: the controller, the blade and the route
were all still in the tree, one env var away from being live. Removing the flag
and the route together means there is no longer a switch that could be turned.

The two census tests that allowed for the dead code now carry **empty** allow
lists rather than the entries deleted — so a reintroduced registration controller
or a `route('register')` in any blade fails the build instead of being absorbed by
an exception. `Auth/AuthFlowTest::test_registration_is_not_reachable_by_default`
was rewritten to assert the stronger property (no named `register` route exists),
not merely that the old flag was false.

### ~~CALC-1 — dashboard receivables ignore invoices settled by credit~~ — FIXED

**Status: fixed.** `DashboardService` now subtracts all three ways an invoice can
be settled, via a single `openReceivable()` helper used by both the executive tile
and the receivables panel:

```php
$total       = (clone $invoices)->sum('total');
$paid        = (clone $invoices)->sum('paid_amount');
$credit      = (clone $invoices)->sum('credit_applied');
$creditNotes = (clone $invoices)->sum('credit_note_applied');

return $total - $paid - $credit - $creditNotes;
```

This mirrors `SalesInvoice::settledAmount()` exactly, so the dashboard and the
model cannot drift again. One figure, computed once, rendered in two places —
rather than the same arithmetic re-derived on each.

### ~~CALC-2 — the "Cash accounts" tile never moves~~ — FIXED

**Status: fixed.** The tile is now the sum of `BankAccount::balance()`, which is
opening + deposits/transfer_in − withdrawals/transfer_out, so cash moves.

Aggregated in PHP across the active accounts rather than in one SQL statement,
because `balance()` is per-account and the two cannot be collapsed without
repeating its rule — the same reasoning as `netBalanceFromRows()` in
`FinancialReportController`.

### ~~CALC-3 — party balances mix currencies~~ — FIXED

**Status: fixed, and this was the only genuine data-integrity defect in the set.**

`SalesCustomer::balance()` summed `total` across every invoice and `amount` across
every payment with **no conversion**, so a customer with a USD 100 invoice and a
PKR 50,000 invoice reported 50,100 — a figure that is not a quantity of anything.
`Supplier::balance()` had the same defect.

Both now multiply each row by its own snapshotted `exchange_rate` before
summing, in SQL:

```php
COALESCE(SUM(total * COALESCE(exchange_rate, 1)), 0)
```

Three deliberate choices:

- **Per row, not a blended rate.** Converting with one average rate would be
  cheaper and wrong. The rate belongs to the document, and the snapshot exists so
  history does not move when a rate is later re-entered.
- **`COALESCE(exchange_rate, 1)`** so a document saved without a rate stays at
  face value rather than dropping out of the balance entirely. This matches what
  the ledger already does with the same column.
- **The opening balance is *not* converted**, because it is entered by hand
  against the company's own books and has no rate to apply.

### ~~CALC-4 — the `overdue` status is never written~~ — FIXED

**Status: fixed.** `app/Console/Commands/MarkOverdueInvoices.php`
(`accounting:mark-overdue`) writes the status, scheduled hourly in
`routes/console.php`.

It flips `sent` and `partially_paid` documents past their `due_date` to
`overdue`, on both `sales_invoices` and `purchase_invoices`, each side in its own
transaction so a failure cannot leave sales marked and purchases unmarked.

`draft`, `paid` and `cancelled` are excluded: a draft has not been issued and has
no due date to be late against, and a settled or cancelled document is not late
however old its date.

Hourly rather than daily so a document does not read "on time" for up to a day
after its due date passes. Both screens now agree, because the status is on the
record rather than re-derived differently in each.

---
### ~~UI-1 — sixteen implemented routes unreachable from the interface~~ — FIXED

**Status: fixed.** The delete actions, four status transitions, the holiday editor
and the invoice template picker are all reachable from the markup, and
`tests/Feature/WiredFeatureTest.php` (21 tests) asserts each one — including the
guards that *withhold* a delete while payments exist, a site is in use, or a
reconciliation is complete. Those guard tests are the point: a delete control that
appears unconditionally is as wrong as one that is missing.

### ~~UI-2 — legacy Breeze styling on the auth and profile screens~~ — FIXED

**Status: fixed, and the ratchet is now fully locked.** `DesignSystemLintTest`'s
`BASELINE` is **empty**.

Every one of the sixteen templates has been migrated to the `ink` / `surface` /
`primary` tokens, so no view in the application contains `bg-white`,
`text-gray-*`, `focus:ring-indigo-*`, `bg-gray-*` or `border-gray-*`. The lint
still exists and still fails the build on any of them, so the ratchet has nowhere
left to give.

Two judgement calls worth recording:

- **`bg-white` on brand is not a violation.** A toggle knob or a badge sitting on
  the primary-coloured hero is white in *both* themes — that is the design.
  Mapping them onto `bg-surface` would make them dark grey in dark mode and invert
  the contrast they are supposed to pop off. They became named utilities
  (`on-brand-knob`, `on-brand-overlay-10`, `on-brand-overlay-15`) so the colour's
  *meaning* is recorded rather than its value.
- **`indigo-*` mapped to `primary-*`.** The old utilities were not neutral
  greys; they were indigo, i.e. a hard-coded copy of the default brand colour that
  ignored whatever the user set. Renaming them to the token is what makes them
  follow the brand.

### ~~UI-3 — a missing employee document 500s instead of 404ing~~ — FIXED

**Status: fixed.** `EmployeeDocumentController::download()` now resolves the path
with `realpath()` and aborts 404 when the file is absent, so a row that outlives
its attachment — an admin deleting the file, or a restore bringing the database
forward without the uploads that were not in the dump — gives a not-found rather
than an error page.

The same resolution doubles as the containment check: the real path must start
inside the resolved storage root, which is what stops a row containing `../` from
reading a file from elsewhere on the server.

**A prefix check was tried first and rejected.** Requiring `file_path` to start
with `employee-documents/` looked stricter but was simply wrong: `store()` is not
the only thing that writes that column, and the route sweep's own fixture writes
under a different subdirectory. It 404'd a valid document. Comparing resolved
paths is correct regardless of which folder an upload happened to land in, and it
catches traversal the prefix test would have missed.

### ~~UI-4 — the login password field had no `type` fallback~~ — FIXED

**Status: fixed.** `resources/views/auth/login.blade.php` now carries a literal
`type="password"` *and* the Alpine `:type` binding:

```html
<input id="password" type="password" :type="visible ? 'text' : 'password'" ...>
```

`:type` is a binding and does nothing until Alpine has booted. Without the static
attribute the browser falls back to `type="text"`, so the password rendered in
clear text for as long as the bundle took to load — visible on screen and in the
page source. The static attribute is the fallback; the binding then takes over and
the show/hide toggle works as intended.

### UI-5 — no form anywhere disables itself while submitting

### ~~UI-5 — no form anywhere disables itself while submitting~~ — FIXED

**Status: fixed.** One capture-phase `submit` listener in `resources/js/app.js`
now guards every form:

```js
document.addEventListener('submit', (event) => { ... }, true);
```

On first submit it marks the form, disables every `button[type=submit]` inside it
and sets `aria-busy`. A second submit while the first is in flight is
`preventDefault`ed and `stopImmediatePropagation`ed, so the document cannot be
posted twice. An 8-second timeout releases the buttons, because a form that never
navigates — a fetch submit, or a validation failure re-rendering in place —
would otherwise stay locked for ever.

**Global, not per-form.** There are hundreds of forms in this application, and an
`x-on:submit` on each is exactly the kind of thing that gets forgotten on the four
hundredth screen. One listener covers all of them, including forms added later.
Keyed on the *form* rather than the button, so a form with several submit buttons
is still protected once any of them is used.

An explicit `data-submitting-guard="off"` escape hatch exists for forms that
legitimately need to submit twice.

**Limitation, stated plainly.** There is no JS test runner in this project, so
nothing executes this handler. `DoubleSubmitGuardTest` (5 tests) pins its
*structure* — that the listener exists, runs in the capture phase, blocks a repeat
and releases the buttons — and `app.js` is syntax-checked with `node --check`. It
does not prove the browser behaviour. Adding Vitest and jsdom for one handler is
not worth the dependency; if a JS runner is ever added, that is the test to
replace with a real one.

### TEST-1 — the authentication flow had no tests at all

Before this pass the suite never POSTed `/login` and never exercised logout, the
lockout, or the profile password change — `MiddlewareAccessTest` only fetched
`GET /login` twice to prove it stayed reachable. `Auth/AuthFlowTest` covers all
of it now.

### TEST-2 — two concurrent suites destroyed each other's database

Every test class runs `migrate:fresh`, so two PHPUnit processes sharing
`companybased_test` drop each other's tables mid-run. The symptom is a burst of
`Deadlock found when trying to get lock` and `Table … doesn't exist` failures
that look exactly like application bugs. `phpunit.sweep.xml` isolates a run onto
`companybased_test_` — see
[Running two suites at the same time](#running-two-suites-at-the-same-time).

---

## Calculation Audit

The direct answer to "is the logic and the arithmetic correct?" — no, not
entirely, and the suite now says so with evidence rather than opinion.

### Proven correct (asserted by tests)

| Area | Property asserted |
|---|---|
| Line maths | `Σ line_total == subtotal` and `subtotal + tax == total`, including 0.05 and 50.25 unit prices (`Domain/CalculationConsistencyTest`, `Domain/MoneyAccessorTest`) |
| Invoice balance | `balance()` subtracts cash, store credit and credit-note settlement; cent-level inputs do not drift |
| Status machine | A paid invoice is `paid`, an unpaid one falls back to `sent`, and credit settles an invoice exactly as cash does |
| POS arithmetic | `total == subtotal - discount + tax`, `change == paid - total`, discount capped at subtotal |
| Customer balance | Exact after 60 invoices, and after half of them are settled (`Stress/BulkDataStressTest`) |
| Document numbering | 40 consecutive calls produce 40 unique, gapless numbers |
| Pagination | No row repeats across pages |
| Payments / inventory / payroll / ledgers | Already covered by `InvoicePaymentTest`, `InventoryLedgerTest`, `PayrollServiceTest`, `GeneralLedgerTest`, `BankLedgerTest` |

### The pinned defects

**None as of 2026-10-09.** The suite no longer skips for a single known defect.

The three that remained — CALC-1, CALC-2, CALC-3 — were fixed this pass and their
tests now run for real rather than skipping. The single remaining skip is
environmental: `InvoicePdfSampleTest` skips unless `SAMPLE_PDF_PATH` is set, and
is a visual-inspection helper rather than an assertion.

| Was | Status | Evidence |
|---|---|---|
| CALC-1 dashboard receivables | **FIXED** | `DashboardService::openReceivable()` subtracts `paid_amount`, `credit_applied` and `credit_note_applied`, mirroring `SalesInvoice::settledAmount()`. |
| CALC-2 cash tile | **FIXED** | Sums `BankAccount::balance()` instead of `opening_balance`. |
| CALC-3 currency mixing | **FIXED** | `SalesCustomer::balance()` and `Supplier::balance()` multiply each row by its own `exchange_rate` before summing. |
| CRITICAL-4 `/api` 500s | **FIXED earlier** | `User` already carries `Laravel\Sanctum\HasApiTokens` (`app/Models/User.php:15,25`); `Api/ApiEndpointsTest` passes 8/8 with a real bearer token. |
| AUTH-1 `access_until` | **FIXED earlier** | In `#[Fillable]` (`:19`) and cast (`:49`); `LoginRequest::authenticate()` now rejects inactive and expired accounts inline. `Auth/AuthFlowTest` passes 14/14. |
| AUTH-2 inactive login | **FIXED earlier** | Same fix — the check moved from the *next* request to the login itself. |
| HIGH-4 misleading copy | **FIXED earlier** | `regain access` / `unrestricted access` appear in neither the blade nor the controller. |

**Keep regenerating this list from `--display-skipped`, not from prose.** Two ids
were listed here long after they stopped skipping, and a third described a defect
that had already been fixed — which is how a "known issue" list rots into fiction.
The census tests now carry *empty* allow-lists for the deleted registration
scaffolding rather than the entries that used to excuse it, so reintroducing dead
code fails the build.

Two further defects are enforced by tests that **fail loudly** rather than skip,
because the fix is a deletion and the suite should be red until it happens:
`Census/ControllerCoverageTest` and `Census/ViewIntegrityTest`. Both are now clear
of the registration pair.

### Previously-open defects now closed

These were listed in earlier revisions of this section and are **fixed**; the
suite no longer skips for any of them. Do not re-open them from this section.

| Id | Status | Evidence |
|---|---|---|
| MEDIUM-2 (floats, PDF recomputing totals) | **FIXED** | `app/Support/Money.php` is a bcmath value object with 110 call sites. Both `DocumentData` blocks — the header and the printed line rows — now delegate to `DocumentItems::lineTotals()`, so the PDF cannot drift from the stored totals. |
| HIGH-3 (`currency:convert` rounding independently) | **FIXED** | `ConvertCurrency.php` converts line items first, derives the header via `DocumentItems::totalsFromPersistedItems()`, converts payments in the same transaction, and uses `bcmul`. Pinned by `ConvertCurrencyTest` (11). |
| AUTH-1 as originally described | **PARTLY** | The *editor* side is still open: the user editor accepts `access_until` and discards it, so the column is never written. What is pinned is the *enforcement* side failing open on a past date. Both halves are in the one AUTH-1 entry above. |

---

## Deployment Guide

### Production checklist

1. Upload code. Run `composer install --no-dev --optimize-autoloader`.
2. Copy `.env.example` to `.env`. Set `APP_KEY` (`php artisan key:generate`), MySQL credentials, `APP_URL`.
3. `php artisan migrate --force` then `php artisan db:seed --force`.
4. `php artisan storage:link` -- logos and uploaded documents are served through it.
5. `npm install && npm run build` -- `public/build` is **gitignored** and **must be built on the server**. It is not committed (see Maintainer Notes).
6. `php artisan optimize` -- caches config, routes, events, and views.
7. Set document root to `public/`. The root `.htaccess` is a fallback for hosts that cannot do this.
8. Install the cron entry (see below). Without it, no queued notification is delivered.

### Cron and queue

**hPanel -> Advanced -> Cron Jobs -> add:**

| Field | Value |
|---|---|
| Type | PHP |
| Command | `/opt/alt/php82/usr/bin/php /home/uXXXXXXX/domains/YOURDOMAIN/artisan schedule:run` |
| Schedule | `* * * * *` |

Verify the PHP path in hTerminal first (`php -v`). The CLI binary is not `/usr/bin/php` on Hostinger.

The `>` operator is not supported in the hPanel PHP cron type. For output redirection, use a Custom cron pointing to a `.sh` wrapper.

**Scheduled tasks:**

| Task | Frequency | Purpose |
|---|---|---|
| `queue:work --stop-when-empty` | every minute | Drains queued mail and notifications |
| `scheduler-heartbeat` | every minute | Writes timestamp for health checks |
| `SubscriptionReminder` | daily | Package-expiry reminder mail |
| `CheckLowStock` | daily at 07:15 | Backstop low-stock sweep |

**Verify:**

```bash
php artisan queue:health
```

A healthy deployment shows a heartbeat under a minute old and zero pending jobs.

### Backup and restore

**Settings -> Backup & Restore** takes a full `mysqldump` and replays an uploaded `.sql` over `mysql` stdin. Manual only -- no scheduled backups.

Config in `config/backups.php`:

| Key | Default | Notes |
|---|---|---|
| `MYSQLDUMP_PATH` | -- | Path to `mysqldump` binary |
| `MYSQL_CLIENT_PATH` | -- | Path to `mysql` binary |
| `BACKUP_KEEP` | 10 | Number of backups to retain |

Behaviour:
- Dumps are streamed to disk, not buffered in PHP memory. Written to a `tmp/` scratch name, moved on success.
- Restore takes a safety backup first and names it in the response.
- Restore requires typing `RESTORE`, invalidates the session, and redirects to sign-in.
- Refused on a non-MySQL connection.
- `BACKUP_KEEP` always keeps at least one backup.

### What a fresh deployment is seeded with

| Table | Rows | Notes |
|---|---|---|
| `users` | 7 | One per role |
| `roles` | 7 | The full role set |
| `permissions` | 387 | Permission registry |
| `role_has_permissions` | 716 | Role-permission matrix |
| `modules` | 16 | Feature toggles (all enabled) |
| `settings` | 82 | Company profile, currency, branding, module defaults and the four mail identities. Not 8. |
| `employees` | 4 | One per staff login so attendance works |

All seeded accounts share the password **`Password123!`**. Change or delete them before the deployment is publicly reachable.

---

## Development Setup

### Prerequisites

- PHP 8.3+, `ext-bcmath`, `ext-intl` (optional)
- MySQL 8.x
- Node.js 20+

### First-time setup

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
npm install
npm run build
```

Or use the Composer convenience script:

```powershell
composer setup
```

### Running locally

```powershell
composer dev
```

Starts `php artisan serve`, `queue:listen`, `pail`, and the Vite dev server concurrently.

### Front-end assets

`public/build` is a committed build artefact loaded via `@vite` in production.

Editing `resources/js/app.js`, `resources/css/app.css`, or any Blade file that uses Tailwind classes does nothing until you rebuild:

```powershell
npm run build
```

If a UI change appears to have no effect, check the timestamp on `public/build/assets/` first.

### Code quality

```powershell
php vendor/bin/pint --dirty
php "C:\laragon\bin\composer\composer.phar" audit
```

---

## Environment Reference

### Local environment

- URL: `http://companybased.test`
- Database: MySQL `companybased`
- Composer: `php "C:\laragon\bin\composer\composer.phar"`

### Seeded accounts (password: `Password123!`)

| Email | Role |
|---|---|
| `superadmin@nexosdigital.test` | Super Admin |
| `admin@nexosdigital.test` | Admin |
| `hr@nexosdigital.test` | HR |
| `accountant@nexosdigital.test` | Accountant |
| `salesman@nexosdigital.test` | Salesman |
| `inventory-manager@nexosdigital.test` | Inventory Manager |
| `employee@nexosdigital.test` | Employee |

### Useful commands

```powershell
# Database
php artisan migrate --force
php artisan db:seed --force

# Cache
php artisan optimize
php artisan config:clear
php artisan cache:clear

# Queue
php artisan queue:work --queue=default --tries=3 --timeout=120
php artisan queue:health

# Scheduler
php artisan schedule:run

# Tests
php vendor/bin/phpunit --no-coverage
php vendor/bin/phpunit tests/Feature/MultiEmailSetupTest.php --no-coverage

# Code quality
php vendor/bin/pint --dirty
php "C:\laragon\bin\composer\composer.phar" audit

# PDF preview (requires: pip install pypdfium2)
php preview_invoice_layout.php
php preview_document_pdfs.php
python render_invoice_pdf.py storage/app/preview-doc-quote.pdf
```

### `.env` keys specific to this project

```dotenv
# Required
DB_DATABASE=companybased
DB_TEST_DATABASE=companybased_test   # must not equal DB_DATABASE

# Mail (base transport)
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=465
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=secret
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Nexos Digital"

# Backup
MYSQLDUMP_PATH="C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe"
MYSQL_CLIENT_PATH="C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe"
BACKUP_KEEP=10
```

Department SMTP credentials are stored in the `settings` table (encrypted), not in `.env`.

---

## Maintainer Notes

- **A `setUp()` in a test class silently disables `Tests\SeedsDatabase::setUp()`.**
  PHP resolves a class method ahead of a trait method, so declaring `setUp()` in a
  test class shadows the trait's, and seeding silently stops happening. Nothing
  errors -- the tests just see an empty database and report "account does not
  exist" or similar, hundreds of lines away from the cause. If a suite suddenly
  cannot see seeded data, delete the `setUp()` override and memoise what it needed
  (`private ?User $admin = null;` with `$this->admin ??= ...`).

  **This was hit twice while writing the production tests**, and the second time the
  symptom was worse and more deceptive: with an empty chart, `LedgerPosting::isReady()`
  returns false, so `post()` returns `null` *by design* and the accounting silently
  does nothing. Every assertion then failed on a null journal, and one account lookup
  reported "no results" because nothing had been seeded at all. A silently skipped
  posting is far nastier than an exception -- if a production test suddenly reports a
  null journal, check for a `setUp()` before you touch the service.

- **`EnsureUserIsActive` logs out any user whose `is_active` is falsy** and redirects
  to `login`, invalidating the session. A permission test that creates a bare
  `User::create()` without `is_active` gets a **302, not a 403**, and the assertion
  that follows fails confusingly. Always set `'is_active' => true` and
  `'email_verified_at' => now()` on a hand-built test user.

- **WIP is not decorative, and it is not always zero.** `ProductionPostingService`
  used to assert that work in progress never carries a balance. That was true when
  components were the only cost, and it stopped being true when labour was absorbed:
  labour now accumulates in `1350` for the life of the order and is credited back out
  at completion. If you change the absorption basis, re-check both that WIP returns
  to zero after completion and that the variance compares **fully absorbed** cost
  against the goods' carrying value. Comparing a components-only cost against an
  absorbed carrying value books a fictional overrun on every order.

- **Absorption credits `6200`, not `2150`.** Payroll already posted
  Dr 6200 / Cr 2150. Crediting the payable again would leave the business owing
  staff twice *and* still expensing the cost, counting it once as expense and again
  as inventory. There is a test pinning the absence of `2150` on an absorption
  journal; do not remove it as over-strict.

- **The tax engine will block existing documents.** Any deployment with a tax
  percentage on saved documents cannot save them until a matching `tax_rates` row
  exists. Grandfathering or a backfill migration is required before real users hit
  this.

- **Write probe scripts as UTF-8 *without* a BOM.** `Set-Content -Encoding UTF8` in
  Windows PowerShell 5.1 writes a BOM, and PHP then rejects the file with
  `Namespace declaration statement has to be the very first statement` — which points
  at line 3 and looks like a syntax error in your code rather than an encoding
  problem. The same trap applies to `Out-File -Encoding utf8`.
- **`User::access_until` is a `date`, not a `datetime`.** The settings form uses
  `<input type="date">` and `UserController` validates it as `date`, so a midnight
  cast is the intended semantic. It also needs to be in `#[Fillable]` -- without
  both, an expired account 500s `EnsureUserIsActive` on every request.
- Session cookie name: `nexos-digital-session`.
- Permission middleware resolves to the Spatie permission middleware class.
- `Setting` and `Module` values are memoized per request. Call `Setting::flushCache()` in tests after writing settings directly.
- Each customer deployment owns its own database, settings, users, roles, modules, currency, subscription, dashboards, holidays, and files. No multi-tenancy within a single installation.
- `ext-intl` is not installed locally -- `money()` renders as `1234.56 PKR` here and `PKR 1,234.56` in production. Test money formatting against the production environment.
- Physical attendance devices must be tested on the actual office network and configured against the deployment subdomain.
- Hostinger document-root configuration should point to `public/` when possible. The root `.htaccess` is a fallback.
- The `preview_*.php` and `render_invoice_pdf.py` scripts in the project root are development tools, not production code. Keep them -- they are the only way to verify PDF layout without a browser.
- `public/build` is **not** committed. It is listed in `.gitignore` and `git ls-files public/build` returns nothing. A deployment that cannot run Node must either build the assets in CI and upload `public/build`, or drop the hashed `@vite` references in `layouts/app.blade.php` for pre-built static assets. Do not assume a checkout contains working CSS or JS.

### Suggested order of remaining work

Ordered by value per hour. The pinned issues turn green by themselves the day they
are fixed, so this list can be worked top-down without re-auditing anything.

**Already done — do not re-attempt.** These were on earlier versions of this list
and are now fixed and verified. Each still has a regression pin or a status table
entry recording it.

| Was | Issue | Now |
|---|---|---|
| 1 | AUTH-1 (persistence half) | `access_until` is in `#[Fillable]` and cast to `date`. Only the enforcement half remains, folded into AUTH-2 below. |
| 6 | HIGH-4 | Copy corrected; `SubscriptionController::deactivate()` now states access is blocked, with a comment explaining why. |
| 7 | MEDIUM-5 | `Sales/InvoiceController::recalculateStatus()` is indented correctly. |
| 8 | HIGH-1 | `Subscription::isAccessBlocked()` is memoised with an explicit `flushCache()`. |

**One-line / one-file fixes still open**

None. Every defect with a file-level fix is now closed.

**Remaining, all deliberate decisions rather than bugs**

| Issue | What it is |
|---|---|
| PROD-1 | Rename Super Admin / Admin, or widen Super Admin. The account handed to a new install cannot manage users. Working as designed; pinned so it cannot drift. This is a **naming/product decision** and renaming roles touches seeded data and every permission string, so it is not something to do unilaterally. |
| LOW-3 | 1 factory for 101 models; `SearchRegistry` wildcard `LIKE`. Test ergonomics and search UX. A `FULLTEXT` index is the right answer for the search half and is a schema change worth its own migration. |
| AUTH-3 removal | The e-mail password reset flow is still shipped but unreachable from the login page. Throttled meanwhile; the removal checklist above describes how to delete it. Whether a business wants self-service resets is a product decision, not a bug. |

**Closed this pass:** CALC-1/2/3/4, AUTH-1/2/3, PROD-2, UI-1/2/3/4/5, LOW-2.

**Small but structural**

5. **CALC-3** — convert through `ExchangeRate` in `SalesCustomer::balance()`
   instead of adding amounts across currencies. The one genuine data-integrity
   defect of this group: a USD and a PKR invoice currently sum to nonsense.
6. **CALC-4** — schedule a command that marks past-due `sent` invoices `overdue`, or
   compute overdue from `due_date` everywhere and drop the status from the queries.
   Today the dashboard and the document disagree.
7. **AUTH-2** — reject `is_active = false` and past `access_until` inside
   `LoginRequest::authenticate()` instead of on the following request. This also
   closes the AUTH-1 enforcement half.
8. **AUTH-3** — add `throttle:` to `password.email` / `password.store`, or delete the
   reset flow per the removal checklist.
9. **CRITICAL-1** — decided, not outstanding. Super Admin is an
   installation-configuration role and deliberately holds no business-module
   permissions, so a 403 on the send-email routes is correct.
   `MultiEmailSetupTest::test_super_admin_cannot_send_business_documents()`
   asserts that 403 so it cannot be "fixed" by accident. Do not widen
   `RolesSeeder::$superAdmin`.
10. **UI-4** — `login.blade.php:19` needs a static `type="password"` before the Alpine
    binding.
11. **UI-3** — `EmployeeDocumentController::download()` needs an `abort_unless(exists, 404)`
    guard.
12. **UI-5** — a shared submit handler that disables the button and shows a spinner.

**Decisions to make, not just code to write**

13. **PROD-1** — rename the Super Admin / Admin roles, or widen Super Admin. The account
    given to a new install cannot manage users.
14. **UI-2 / UI-1** — migrate the baselined Blades to the design tokens and wire the
    sixteen server-side actions that have no control in the interface. Both lists are
    enforced by tests, so the migration shrinks them one file at a time.

**Closed since this list was first written.** `MEDIUM-1` (automatic journals), `MEDIUM-2`
(`Money` value object), `MEDIUM-3` (SQL aggregation), `HIGH-2` (CashFlow) and `HIGH-3`
(`currency:convert`) are all implemented and pinned — see the
[Known Issues](#known-issues) table for the file and line evidence. Do not re-open
them from this list; they are no longer decisions.

### New work, in priority order

The numbered list above is pre-existing pinned issues. These are the open threads
from the 2026-10-08 batch, most urgent first.

| # | Item | Why it matters |
|---|---|---|
| 1 | ~~**Tax grandfathering / backfill**~~ | **DONE.** `2026_10_09_000001_adopt_existing_tax_rates.php` adopts the rates existing documents already charge, dated from the earliest document that used each. Applied to the working database and rehearsed on a copy of real data: all legacy documents save, unconfigured rates are still refused. See [Tax rate engine](#tax-rate-engine). |
| 2 | ~~**Commit the batch**~~ | **DONE.** Committed as `21ede82` (321 files). A pre-migration backup was taken first. |
| 3 | **Work centres / machines** | Blocks machine-hour and rate-per-unit overhead absorption. Currently labour-hour only, which will mislead any business running capital equipment. |
| 4 | **Timesheet integration** | Absorption is hand-entered minutes. Attendance already records `work_minutes`; nothing connects the two. |
| 5 | **MRP raising purchase orders** | The plan is read-only by design. A "raise POs for net requirements" action, gated on confirming the plan, is the natural next step. |
| 6 | **Forward scheduling / capacity** | Net requirements answer *what*, not *when*. Lead-time sequencing and capacity loading are the remainder of MRP. |
| 7 | **Historical FX correction** | Rows backfilled at rate 1. Decide whether to restate them or document the position permanently. |
| 8 | **Rename the two adopted tax rates** | Before going live, confirm each `ADOPTED-*` rate: give it the business's own name and code, and set `account_id` to `2200` Output Tax Payable (or `1310` for a purchase-scope rate). The migration deliberately leaves `account_id` NULL, so until this is done collected tax does not post to a tax liability account. |
| 9 | **LOW-2 rail on page 2+** | A redesign, not a patch: the rail must move out of the two-cell table so DomPDF can repeat it. See [Known Issues](#known-issues) for why the obvious fix was rejected. |

### Production settings

| Setting key | Default | Meaning |
|---|---|---|
| `production.standard_monthly_hours` | `208` | Divisor turning a monthly salary into an hourly rate. 208 = 26 days x 8h. Six-day weeks need this raised. |
| `production.overhead_rate_per_hour` | `0` | Manufacturing overhead absorbed per direct labour hour. Zero means overhead is not absorbed at all, which is deliberate: absorbing an unconfigured rate would invent factory cost and put it into inventory. |

Both are read through `Setting::get()` and are memoized per request. Call
`Setting::flushCache()` in tests after writing them directly.