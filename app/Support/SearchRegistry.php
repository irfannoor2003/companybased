<?php

namespace App\Support;

use App\Models;
use App\Models\Module;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * Whitelist of globally searchable entities.
 *
 * Each entry declares the permission required to see its results, so a
 * Salesman can search customers and orders but never bank accounts or journal
 * entries, and a Search result can never leak a record the user may not open.
 *
 * `module` is checked as well as `permission`: a disabled module removes its
 * entries from search entirely, matching how the sidebar behaves.
 */
final class SearchRegistry
{
    /**
     * @return array<string, array{label: string, singular: string, icon: string, module: string, permission: string, model: class-string, columns: array<int, string>, display: array<string, string>, route: string, route_param: string}>
     */
    public static function entities(): array
    {
        return [
            'sales_customers' => [
                'label' => 'Customers',
                'singular' => 'Customer',
                'icon' => 'users',
                'module' => 'sales',
                'permission' => 'sales.customers.view',
                'model' => Models\SalesCustomer::class,
                'columns' => ['company_name', 'short_code', 'contact_name', 'email', 'phone', 'tax_number'],
                'display' => ['title' => 'company_name', 'subtitle' => 'contact_name', 'meta' => 'short_code'],
                'route' => 'sales.customers.show',
                'route_param' => 'customer',
            ],
            'sales_orders' => [
                'label' => 'Orders',
                'singular' => 'Order',
                'icon' => 'orders',
                'module' => 'sales',
                'permission' => 'sales.orders.view',
                'model' => Models\SalesOrder::class,
                'columns' => ['number', 'shipping_address', 'notes'],
                'display' => ['title' => 'number', 'subtitle' => 'status', 'meta' => 'total'],
                'route' => 'sales.orders.edit',
                'route_param' => 'order',
            ],
            'sales_invoices' => [
                'label' => 'Invoices',
                'singular' => 'Invoice',
                'icon' => 'invoice',
                'module' => 'sales',
                'permission' => 'sales.invoices.view',
                'model' => Models\SalesInvoice::class,
                'columns' => ['number'],
                'display' => ['title' => 'number', 'subtitle' => 'status', 'meta' => 'total'],
                'route' => 'sales.invoices.show',
                'route_param' => 'invoice',
            ],
            'suppliers' => [
                'label' => 'Suppliers',
                'singular' => 'Supplier',
                'icon' => 'suppliers',
                'module' => 'suppliers',
                'permission' => 'suppliers.suppliers.view',
                'model' => Models\Supplier::class,
                'columns' => ['company_name', 'short_code', 'contact_name', 'email', 'phone'],
                'display' => ['title' => 'company_name', 'subtitle' => 'contact_name', 'meta' => 'short_code'],
                'route' => 'suppliers.suppliers.show',
                'route_param' => 'supplier',
            ],
            'purchase_orders' => [
                'label' => 'Purchase Orders',
                'singular' => 'Purchase Order',
                'icon' => 'orders',
                'module' => 'suppliers',
                'permission' => 'suppliers.purchase_orders.view',
                'model' => Models\PurchaseOrder::class,
                'columns' => ['number'],
                'display' => ['title' => 'number', 'subtitle' => 'status', 'meta' => 'total'],
                'route' => 'suppliers.purchase_orders.show',
                'route_param' => 'purchase_order',
            ],
            'products' => [
                'label' => 'Products',
                'singular' => 'Product',
                'icon' => 'package',
                'module' => 'catalog',
                'permission' => 'catalog.products.view',
                'model' => Models\Product::class,
                'columns' => ['name', 'sku', 'barcode'],
                'display' => ['title' => 'name', 'subtitle' => 'sku', 'meta' => null],
                'route' => 'catalog.products.edit',
                'route_param' => 'product',
            ],
            'inventory_items' => [
                'label' => 'Stock Items',
                'singular' => 'Stock Item',
                'icon' => 'package',
                'module' => 'inventory',
                'permission' => 'inventory.items.view',
                'model' => Models\InventoryItem::class,
                'columns' => [],
                'display' => ['title' => 'product_name', 'subtitle' => 'product_sku', 'meta' => 'on_hand'],
                'route' => 'inventory.items.show',
                'route_param' => 'item',
                'joins' => ['product'],
            ],
            'employees' => [
                'label' => 'Employees',
                'singular' => 'Employee',
                'icon' => 'employees',
                'module' => 'employees',
                'permission' => 'employees.employees.view',
                'model' => Models\Employee::class,
                'columns' => ['employee_code', 'first_name', 'last_name', 'email', 'phone'],
                'display' => ['title' => 'name', 'subtitle' => 'job_title', 'meta' => 'employee_code'],
                'route' => 'employees.employees.show',
                'route_param' => 'employee',
            ],
            'bank_accounts' => [
                'label' => 'Bank Accounts',
                'singular' => 'Bank Account',
                'icon' => 'banking',
                'module' => 'banking',
                'permission' => 'banking.accounts.view',
                'model' => Models\BankAccount::class,
                'columns' => ['name', 'account_number'],
                'display' => ['title' => 'name', 'subtitle' => 'account_number', 'meta' => 'account_type'],
                'route' => 'banking.accounts.show',
                'route_param' => 'account',
            ],
            'accounts' => [
                'label' => 'Chart of Accounts',
                'singular' => 'Account',
                'icon' => 'database',
                'module' => 'accounting',
                'permission' => 'accounting.chart_of_accounts.view',
                'model' => Models\Account::class,
                'columns' => ['name', 'code'],
                'display' => ['title' => 'name', 'subtitle' => 'code', 'meta' => 'type'],
                'route' => 'accounting.accounts.edit',
                'route_param' => 'account',
            ],
            'warehouses' => [
                'label' => 'Warehouses',
                'singular' => 'Warehouse',
                'icon' => 'building',
                'module' => 'inventory',
                'permission' => 'inventory.warehouses.view',
                'model' => Models\InventoryWarehouse::class,
                'columns' => ['name', 'code'],
                'display' => ['title' => 'name', 'subtitle' => 'code', 'meta' => null],
                'route' => 'inventory.warehouses.edit',
                'route_param' => 'warehouse',
            ],
            'credit_notes' => [
                'label' => 'Credit Notes',
                'singular' => 'Credit Note',
                'icon' => 'credit',
                'module' => 'sales',
                'permission' => 'sales.credit_notes.view',
                'model' => Models\SalesCreditNote::class,
                'columns' => ['number', 'reason'],
                'display' => ['title' => 'number', 'subtitle' => 'reason', 'meta' => 'total'],
                'route' => 'sales.credit_notes.show',
                'route_param' => 'credit_note',
            ],
        ];
    }

    /**
     * Entities the given user is allowed to search: permission held and module
     * enabled. This is the single gate used by both the JSON endpoint and the
     * full results page, so the two can never disagree.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forUser(?object $user): array
    {
        if (! $user) {
            return [];
        }

        return array_filter(self::entities(), function (array $entity) use ($user): bool {
            if (! $user->can($entity['permission'])) {
                return false;
            }

            return Module::isEnabled($entity['module']);
        });
    }

    /**
     * Run a scoped search across every permitted entity.
     *
     * @param  array<string, array<string, mixed>>  $entities
     * @return array<int, array<string, mixed>>
     */
    public static function query(array $entities, string $term, int $perEntity = 5): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $results = [];

        foreach ($entities as $key => $entity) {
            $model = $entity['model'];
            $instance = new $model;

            $builder = $instance->newQuery();

            // Nested display fields (product_name) are searched through a relation.
            $searchColumns = $entity['columns'];
            if ($searchColumns === []) {
                $searchColumns = ['id'];
            }

            $builder->where(function (Builder $q) use ($searchColumns, $term): void {
                foreach ($searchColumns as $column) {
                    $q->orWhere($column, 'like', '%'.$term.'%');
                }
            });

            // Inventory items carry no searchable columns of their own; match on
            // the related product's name/SKU instead.
            if ($key === 'inventory_items') {
                $builder->whereHas('product', function (Builder $q) use ($term): void {
                    $q->where('name', 'like', '%'.$term.'%')
                        ->orWhere('sku', 'like', '%'.$term.'%')
                        ->orWhere('barcode', 'like', '%'.$term.'%');
                });
            }

            $rows = $builder->limit($perEntity)->get();

            if ($rows->isEmpty()) {
                continue;
            }

            foreach ($rows as $row) {
                $results[] = self::present($key, $entity, $row);
            }
        }

        return $results;
    }

    /**
     * Shape a model row into a search result payload.
     *
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>
     */
    private static function present(string $key, array $entity, object $row): array
    {
        $display = $entity['display'];

        $title = self::attribute($row, $display['title'] ?? null);
        $subtitle = self::attribute($row, $display['subtitle'] ?? null);
        $meta = self::attribute($row, $display['meta'] ?? null);

        $url = self::urlFor($entity, $row);

        return [
            'entity' => $key,
            'type' => $entity['singular'],
            'label' => $entity['label'],
            'icon' => $entity['icon'],
            'title' => (string) ($title ?: ('#'.$row->getKey())),
            'subtitle' => $subtitle !== null ? (string) $subtitle : null,
            'meta' => $meta !== null ? (string) $meta : null,
            'url' => $url,
        ];
    }

    /**
     * Read a display field, which may be a real column, an accessor, or a
     * relation flattened by the model.
     */
    private static function attribute(object $row, ?string $field): mixed
    {
        if (! $field) {
            return null;
        }

        // product_name / product_sku are flattened from the product relation.
        if (str_starts_with($field, 'product_')) {
            $product = $row->product ?? null;

            return $product?->{substr($field, 8)};
        }

        if ($field === 'on_hand') {
            return InventoryLedger::onHand((int) $row->id);
        }

        $value = $row->{$field} ?? null;

        // Enum-ish columns should render as readable labels.
        if (in_array($field, ['status', 'type', 'employment_status'], true) && is_string($value)) {
            return ucfirst(str_replace('_', ' ', $value));
        }

        return $value;
    }

    /**
     * Build the result URL, but only when the target route actually exists and
     * the user can reach it — otherwise fall back to the entity's index page.
     *
     * @param  array<string, mixed>  $entity
     */
    private static function urlFor(array $entity, object $row): ?string
    {
        if ($entity['route'] && Route::has($entity['route'])) {
            try {
                return route($entity['route'], [$entity['route_param'] => $row->getKey()]);
            } catch (\Throwable) {
                // Fall through to the index.
            }
        }

        return null;
    }
}
