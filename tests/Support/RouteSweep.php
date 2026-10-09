<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * Route inventory + parameter resolver for the census sweeps.
 *
 * The sweeps need three things the framework does not give us for free:
 *
 *  1. Which routes to exercise, and which to skip (with a written reason, so
 *     a skip can never silently hide a broken page).
 *  2. A concrete value for every route-model-binding parameter. The same
 *     parameter name maps to different tables depending on the URI prefix
 *     ({invoice} is sales_invoices under /sales and purchase_invoices under
 *     /suppliers), so resolution is prefix-aware.
 *  3. Fresh rows for destructive verbs, so a DELETE cannot invalidate the
 *     cached fixture another route still points at.
 */
class RouteSweep
{
    /**
     * @var array<string, string> route parameter => table
     */
    private const PARAM_TABLES = [
        'account' => 'accounts',
        'asset' => 'fixed_assets',
        'bill' => 'bills',
        'billOfMaterial' => 'inventory_bill_of_materials',
        'brand' => 'brands',
        'budget' => 'budgets',
        'category' => 'categories',
        'claim' => 'expense_claims',
        'contribution' => 'capital_contributions',
        'creditNote' => 'sales_credit_notes',
        'customer' => 'sales_customers',
        'customReport' => 'custom_reports',
        'debitNote' => 'debit_notes',
        'deliveryNote' => 'sales_delivery_notes',
        'department' => 'departments',
        'discountRule' => 'discount_rules',
        'disposal' => 'fixed_asset_disposals',
        'dividend' => 'investment_dividends',
        'document' => 'employee_documents',
        'drawing' => 'capital_drawings',
        'employee' => 'employees',
        'entry' => 'journal_entries',
        'holiday' => 'holidays',
        'investment' => 'investments',
        'item' => 'inventory_items',
        'leave' => 'leave_requests',
        'module' => 'modules',
        'order' => 'sales_orders',
        'payment' => 'sales_payments',
        'paymentMethod' => 'pos_payment_methods',
        'pitstop' => 'visit_pitstops',
        'priceList' => 'price_lists',
        'product' => 'products',
        'quote' => 'sales_quotes',
        'reconciliation' => 'reconciliations',
        'record' => 'attendance_records',
        'recurringInvoice' => 'sales_recurring_invoices',
        'role' => 'roles',
        'rule' => 'notification_rules',
        'run' => 'payroll_runs',
        'sale' => 'pos_sales',
        'shift' => 'pos_shifts',
        'shipment' => 'inventory_incoming_shipments',
        'structure' => 'salary_structures',
        'supplier' => 'suppliers',
        'tax' => 'tax_returns',
        'template' => 'document_templates',
        'transfer' => 'inventory_transfers',
        'transaction' => 'bank_transactions',
        'user' => 'users',
        'visit' => 'visits',
        'warehouse' => 'inventory_warehouses',
        'withholdingTaxReceipt' => 'withholding_tax_receipts',
        'writeOff' => 'inventory_write_offs',
    ];

    /**
     * Same parameter, different table when the URI is under another prefix.
     *
     * @var array<string, array{0: string, 1: string}> "uri prefix" => [param, table]
     */
    private const PREFIX_OVERRIDES = [
        'banking/accounts' => ['account', 'bank_accounts'],
        'banking/transactions' => ['transaction', 'bank_transactions'],
        'banking/transfers' => ['transfer', 'bank_transfers'],
        'suppliers/purchase-invoices' => ['invoice', 'purchase_invoices'],
        'suppliers/purchase-orders' => ['order', 'purchase_orders'],
        'suppliers/purchase-quotes' => ['quote', 'purchase_quotes'],
        'suppliers/supplier-payments' => ['payment', 'supplier_payments'],
        'sales/invoices' => ['invoice', 'sales_invoices'],
        'investments/transactions' => ['transaction', 'investment_transactions'],
        'inventory/production-order' => ['order', 'inventory_production_orders'],
        'inventory/production-orders' => ['order', 'inventory_production_orders'],
    ];

    /**
     * GET routes skipped by the sweep, with the reason recorded in the README.
     *
     * @var array<string, string> uri => reason
     */
    private const SKIP_GET = [
        'verify-email/{id}/{hash}' => 'Requires a signed URL; the verification feature is dormant (User does not implement MustVerifyEmail). Covered by AuthFlowTest instead.',
        'api/user' => 'Requires auth:sanctum bearer/stateful auth; covered by ApiEndpointsTest.',
        'api/attendance/scan' => 'Requires auth:sanctum + payload; covered by ApiEndpointsTest.',
    ];

    /**
     * Write routes skipped by the sweep, with reasons.
     *
     * Keys must be the route's real URI ($route->uri()), not its route name:
     * POST and DELETE on `settings/subscription` share the URI
     * `settings/subscription`, and a mistyped key silently lets a
     * state-mutating route run inside the sweep.
     *
     * @var array<string, string> uri => reason
     */
    private const SKIP_WRITE = [
        'logout' => 'Invalidates the shared session mid-sweep; covered by AuthFlowTest.',
        'backups' => 'POST shells out to mysqldump (slow, external binary); covered by DatabaseBackupTest.',
        'backups/restore' => 'POST shells out to the mysql client; covered by DatabaseBackupTest.',
        'settings/modules/{module}' => 'Could disable a module for every later route in the sweep; covered by ModuleToggleTest.',
        'settings/subscription' => 'POST re-arms the package and DELETE deactivates it. A deactivation mid-sweep makes CheckSubscription 403 every later route, which reads as a mass authorisation failure; both paths are covered by MiddlewareAccessTest.',
        'notifications/{notification}/read' => 'Consumes the acting user notification a later route still points at; covered by NotificationRenderingTest.',
    ];

    public function __construct(private readonly RecordFactory $factory)
    {
    }

    /**
     * All HTTP routes the web application serves (web + api).
     *
     * @return array<int, array{method: string, uri: string, name: string|null, middleware: array<int, string>}>
     */
    public function all(): array
    {
        $out = [];

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $out[] = [
                    'method' => $method,
                    'uri' => $route->uri(),
                    'name' => $route->getName(),
                    'middleware' => $route->gatherMiddleware(),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{method: string, uri: string, name: string|null, middleware: array<int, string>}>
     */
    public function gets(): array
    {
        return array_values(array_filter($this->all(), fn (array $r) => $r['method'] === 'GET' && ! $this->skipGet($r['uri'])));
    }

    /**
     * @return array<int, array{method: string, uri: string, name: string|null, middleware: array<int, string>}>
     */
    public function writes(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $r) => in_array($r['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && ! $this->skipWrite($r['method'], $r['uri']),
        ));
    }

    public function skipGet(string $uri): ?string
    {
        return self::SKIP_GET[$uri] ?? null;
    }

    public function skipWrite(string $method, string $uri): ?string
    {
        if (isset(self::SKIP_WRITE[$uri])) {
            return self::SKIP_WRITE[$uri];
        }

        if ($method === 'DELETE' && $uri === 'settings/backups/{file}') {
            return 'File fixture lifecycle; covered by DatabaseBackupTest.';
        }

        return null;
    }

    /**
     * True when a guest must never see 200 on this route (auth-protected).
     *
     * @param  array<int, string>  $middleware
     */
    public function requiresAuth(array $middleware): bool
    {
        foreach ($middleware as $m) {
            $name = explode(':', (string) $m, 2)[0];

            if (in_array($name, ['auth', 'auth:sanctum'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve one route parameter to a concrete, existing value.
     */
    public function resolveParam(string $param, string $uri, User $user): int|string
    {
        return match ($param) {
            // Public tracking page: the value is a tracking_code, not a key.
            'code' => str_starts_with($uri, 'track/')
                ? $this->trackingCode()
                : $this->factory->keyFor('employees', ['employee_code' => 'SWEEP-'.uniqid()]),

            // Guest-only token route; any non-empty segment reaches the form.
            'token' => 'sweep-token',

            // Backup file: create a real file so download/destroy resolve.
            'file' => $this->backupFile(),

            // Local file serving: a missing path must 404/403, never 500.
            'path' => 'sweep/does-not-exist.txt',

            // Visit/pitstop image endpoints answer 404 unless an image was
            // uploaded; that is correct behaviour, so the fixture stores a
            // real file to exercise the success path too.
            'visit' => str_ends_with($uri, '/completion-image')
                ? $this->visitWithCompletionImage()
                : $this->factory->keyFor('visits'),
            'pitstop' => $this->pitstopWithImage(),

            // Employee document download streams app/public/<file_path>, so
            // the fixture must point at a file that exists or the route 500s
            // inside response()->download().
            'document' => $this->employeeDocument(),

            // Signed email-verification link: skipped entirely (SKIP_GET).
            'id' => (string) $user->id,
            'hash' => sha1($user->email),

            // The notification must belong to the user the sweep signs in as.
            'notification' => $this->notificationFor($user),

            default => $this->factory->routeKeyFor($this->tableFor($param, $uri)),
        };
    }

    /**
     * A brand-new row for destructive verbs (DELETE) so the cached fixture
     * other routes point at is never the row being deleted.
     */
    public function freshKeyFor(string $param, string $uri): int|string
    {
        $table = $this->tableFor($param, $uri);

        return $this->factory->routeKeyForNew($table);
    }

    public function tableFor(string $param, string $uri): string
    {
        foreach (self::PREFIX_OVERRIDES as $prefix => [$overrideParam, $table]) {
            if ($param === $overrideParam && str_starts_with($uri, $prefix)) {
                return $table;
            }
        }

        if (isset(self::PARAM_TABLES[$param])) {
            return self::PARAM_TABLES[$param];
        }

        // Last resort: pluralise the parameter name. Fails loudly in the sweep
        // if a brand-new parameter appears without a table mapping.
        return Str::plural(Str::snake($param));
    }

    /**
     * Substitute every {param} in a URI with a resolvable value.
     */
    public function fillUri(string $uri, User $user, bool $fresh = false): string
    {
        $filled = preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($uri, $user, $fresh) {
            $value = $fresh
                ? $this->freshKeyFor($m[1], $uri)
                : $this->resolveParam($m[1], $uri, $user);

            return rawurlencode((string) $value);
        }, $uri) ?? $uri;

        // A few GET endpoints are data endpoints rather than pages and require
        // a query string; without it they answer 422 by design.
        return $filled.(self::QUERY_STRINGS[$uri] ?? '');
    }

    /**
     * Query strings a few GET routes need to exercise their success path.
     *
     * @var array<string, string> uri => query string
     */
    private const QUERY_STRINGS = [
        'settings/company/attendance-qr-code' => '?text=Company+Attendance+QR',
    ];

    /**
     * GET routes that carry an auth middleware — the guest sweep asserts an
     * unauthenticated visitor never gets 200 from any of them.
     *
     * @return array<int, array{method: string, uri: string, name: string|null, middleware: array<int, string>}>
     */
    public function authedGets(): array
    {
        return array_values(array_filter(
            $this->gets(),
            fn (array $r) => $this->requiresAuth($r['middleware']),
        ));
    }

    /**
     * Skipped routes, for the README's skip report.
     *
     * @return array<string, string>
     */
    public function skipReport(): array
    {
        return self::SKIP_GET + self::SKIP_WRITE;
    }

    /**
     * The permission a route requires, taken from its middleware, or null when
     * the route is open to any signed-in user.
     *
     * @param  array<int, string>  $middleware
     */
    public function requiredPermission(array $middleware): ?string
    {
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'permission:')) {
                return substr($m, strlen('permission:'));
            }
        }

        return null;
    }

    private ?string $trackingCode = null;

    private function trackingCode(): string
    {
        if ($this->trackingCode === null) {
            $this->factory->keyFor('sales_orders', [
                'tracking_code' => $this->trackingCode = 'TRK-'.uniqid(),
            ]);
        }

        return $this->trackingCode;
    }

    /** @var array<int, string> */
    private array $backupFiles = [];

    private function backupFile(): string
    {
        if ($this->backupFiles === []) {
            \Illuminate\Support\Facades\Storage::fake('backups');
        }

        $name = 'sweep-dummy-'.uniqid().'.sql';
        \Illuminate\Support\Facades\Storage::disk('backups')->put($name, '-- sweep fixture');

        return $this->backupFiles[] = $name;
    }

    private function notificationFor(User $user): string
    {
        $existing = $user->notifications()->first();

        if ($existing) {
            return (string) $existing->id;
        }

        $user->notify(new \App\Notifications\SystemNotification(
            'Sweep notification',
            'Created by the route sweep so notifications/{notification} resolves.',
        ));

        return (string) $user->notifications()->first()->id;
    }

    private ?int $documentId = null;

    /**
     * An employee document whose file really exists on the public disk.
     *
     * EmployeeDocumentController::download() calls
     * response()->download(storage_path('app/public/'.$document->file_path))
     * with no existence check, so a fixture row whose file_path points at
     * nothing turns the route into a 500.
     */
    private function employeeDocument(): int
    {
        if ($this->documentId !== null) {
            return $this->documentId;
        }

        $path = 'sweep/'.uniqid().'.txt';

        \Illuminate\Support\Facades\Storage::disk('public')->put($path, 'sweep fixture');

        return $this->documentId = (int) $this->factory->keyFor('employee_documents', [
            'file_path' => $path,
            'original_name' => 'sweep.txt',
            'title' => 'Sweep document',
        ]);
    }

    private ?int $visitWithImageId = null;

    private function visitWithCompletionImage(): int
    {
        if ($this->visitWithImageId !== null) {
            return $this->visitWithImageId;
        }

        $path = 'sweep/'.uniqid().'.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, 'sweep image');

        return $this->visitWithImageId = (int) $this->factory->keyFor('visits', [
            'completion_image_path' => $path,
        ]);
    }

    private ?int $pitstopWithImageId = null;

    private function pitstopWithImage(): int
    {
        if ($this->pitstopWithImageId !== null) {
            return $this->pitstopWithImageId;
        }

        $path = 'sweep/'.uniqid().'.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, 'sweep image');

        return $this->pitstopWithImageId = (int) $this->factory->keyFor('visit_pitstops', [
            'image_path' => $path,
        ]);
    }
}
