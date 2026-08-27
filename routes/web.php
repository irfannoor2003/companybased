<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\BillController;
use App\Http\Controllers\Accounting\BudgetController;
use App\Http\Controllers\Accounting\ExpenseClaimController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\TaxReturnController;
use App\Http\Controllers\Banking\BankAccountController;
use App\Http\Controllers\Banking\BankTransactionController;
use App\Http\Controllers\Banking\BankTransferController;
use App\Http\Controllers\Banking\ReconciliationController;
use App\Http\Controllers\Capital\ContributionController;
use App\Http\Controllers\Capital\DrawingController;
use App\Http\Controllers\Capital\EquityController;
use App\Http\Controllers\Capital\StatementController as CapitalStatementController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\PriceListController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\CustomReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Employees\AttendanceController;
use App\Http\Controllers\Employees\AttendanceReportController;
use App\Http\Controllers\Employees\AttendanceRulesController;
use App\Http\Controllers\Employees\DepartmentController;
use App\Http\Controllers\Employees\EmployeeController;
use App\Http\Controllers\Employees\EmployeeDocumentController;
use App\Http\Controllers\Employees\MyAttendanceController;
use App\Http\Controllers\Employees\PayrollRunController;
use App\Http\Controllers\Employees\SalaryStructureController;
use App\Http\Controllers\FixedAsset\AssetController;
use App\Http\Controllers\FixedAsset\DepreciationController;
use App\Http\Controllers\FixedAsset\DisposalController;
use App\Http\Controllers\FixedAsset\ReportController as AssetReportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Inventory\BillOfMaterialController;
use App\Http\Controllers\Inventory\IncomingShipmentController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\ProductionOrderController;
use App\Http\Controllers\Inventory\TransferController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\Inventory\WriteOffController;
use App\Http\Controllers\Investment\DividendController;
use App\Http\Controllers\Investment\PortfolioController;
use App\Http\Controllers\Investment\ReportController as InvestmentReportController;
use App\Http\Controllers\Investment\ReturnController;
use App\Http\Controllers\Investment\TransactionController;
use App\Http\Controllers\Pos\PaymentMethodController as PosPaymentMethodController;
use App\Http\Controllers\Pos\ReceiptController;
use App\Http\Controllers\Pos\ReconciliationController as PosReconciliationController;
use App\Http\Controllers\Pos\SaleScreenController;
use App\Http\Controllers\Pos\ShiftController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\TrackController;
use App\Http\Controllers\Purchasing\DebitNoteController;
use App\Http\Controllers\Purchasing\PurchaseInvoiceController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseQuoteController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Purchasing\SupplierLedgerController;
use App\Http\Controllers\Purchasing\SupplierPaymentController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\Sales\CreditNoteController;
use App\Http\Controllers\Sales\CustomerController;
use App\Http\Controllers\Sales\DeliveryNoteController;
use App\Http\Controllers\Sales\InvoiceController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Sales\PaymentController;
use App\Http\Controllers\Sales\QuoteController;
use App\Http\Controllers\Sales\RecurringInvoiceController;
use App\Http\Controllers\Sales\StatementController;
use App\Http\Controllers\Sales\TrackingController;
use App\Http\Controllers\Sales\WithholdingTaxReceiptController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\DiscountRuleController;
use App\Http\Controllers\Settings\BackupController;
use App\Http\Controllers\Settings\CompanyController;
use App\Http\Controllers\Settings\CurrencyController;
use App\Http\Controllers\Settings\MailSettingsController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\Settings\TemplateController;
use App\Http\Controllers\Settings\ModuleController;
use App\Http\Controllers\Settings\NotificationRuleController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\UserController;
use App\Http\Controllers\Visits\VisitMapController;
use App\Http\Controllers\Visits\VisitPitStopController;
use App\Http\Controllers\Visits\VisitsController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

Route::get('/employees/attendance/qr/{code}', [AttendanceController::class, 'qr'])
    ->middleware(['module:employees', 'throttle:20,1'])
    ->name('employees.attendance.qr');

Route::get('/track/{code}', [TrackController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('public.tracking');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->middleware(['verified', 'permission:dashboard.overview.view'])
        ->name('dashboard');

    Route::get('/reports', [ReportsController::class, 'index'])
        ->middleware(['permission:reports.reports.view'])
        ->name('reports.index');

    Route::prefix('reports/custom')->name('reports.custom.')->middleware('permission:reports.reports.view')->group(function () {
        Route::get('/', [CustomReportController::class, 'index'])->name('index');
        Route::get('/create', [CustomReportController::class, 'create'])->name('create');
        Route::post('/', [CustomReportController::class, 'store'])->name('store');
    });

    Route::prefix('catalog')->name('catalog.')->middleware('module:catalog')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])
            ->middleware('permission:catalog.products.view')->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])
            ->middleware('permission:catalog.products.create')->name('products.create');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])
            ->middleware('permission:catalog.products.edit')->name('products.edit');
        Route::get('/products/export', [ProductController::class, 'export'])
            ->middleware('permission:catalog.products.export')->name('products.export');
        Route::post('/products', [ProductController::class, 'store'])
            ->middleware('permission:catalog.products.create')->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])
            ->middleware('permission:catalog.products.edit')->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])
            ->middleware('permission:catalog.products.delete')->name('products.destroy');

        Route::get('/brands', [BrandController::class, 'index'])
            ->middleware('permission:catalog.brands.view')->name('brands.index');
        Route::get('/brands/create', [BrandController::class, 'create'])
            ->middleware('permission:catalog.brands.create')->name('brands.create');
        Route::get('/brands/{brand}/edit', [BrandController::class, 'edit'])
            ->middleware('permission:catalog.brands.edit')->name('brands.edit');
        Route::get('/brands/export', [BrandController::class, 'export'])
            ->middleware('permission:catalog.brands.export')->name('brands.export');
        Route::post('/brands', [BrandController::class, 'store'])
            ->middleware('permission:catalog.brands.create')->name('brands.store');
        Route::put('/brands/{brand}', [BrandController::class, 'update'])
            ->middleware('permission:catalog.brands.edit')->name('brands.update');
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])
            ->middleware('permission:catalog.brands.delete')->name('brands.destroy');

        Route::get('/categories', [CategoryController::class, 'index'])
            ->middleware('permission:catalog.categories.view')->name('categories.index');
        Route::get('/categories/create', [CategoryController::class, 'create'])
            ->middleware('permission:catalog.categories.create')->name('categories.create');
        Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])
            ->middleware('permission:catalog.categories.edit')->name('categories.edit');
        Route::get('/categories/export', [CategoryController::class, 'export'])
            ->middleware('permission:catalog.categories.export')->name('categories.export');
        Route::post('/categories', [CategoryController::class, 'store'])
            ->middleware('permission:catalog.categories.create')->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])
            ->middleware('permission:catalog.categories.edit')->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
            ->middleware('permission:catalog.categories.delete')->name('categories.destroy');

        Route::get('/price-lists', [PriceListController::class, 'index'])
            ->middleware('permission:catalog.price_lists.view')->name('price_lists.index');
        Route::get('/price-lists/create', [PriceListController::class, 'create'])
            ->middleware('permission:catalog.price_lists.create')->name('price_lists.create');
        Route::get('/price-lists/{priceList}/edit', [PriceListController::class, 'edit'])
            ->middleware('permission:catalog.price_lists.edit')->name('price_lists.edit');
        Route::get('/price-lists/export', [PriceListController::class, 'export'])
            ->middleware('permission:catalog.price_lists.export')->name('price_lists.export');
        Route::post('/price-lists', [PriceListController::class, 'store'])
            ->middleware('permission:catalog.price_lists.create')->name('price_lists.store');
        Route::put('/price-lists/{priceList}', [PriceListController::class, 'update'])
            ->middleware('permission:catalog.price_lists.edit')->name('price_lists.update');
        Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy'])
            ->middleware('permission:catalog.price_lists.delete')->name('price_lists.destroy');
    });

    Route::prefix('sales')->name('sales.')->middleware('module:sales')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('permission:sales.customers.view')->name('customers.index');
        Route::get('/customers/create', [CustomerController::class, 'create'])
            ->middleware('permission:sales.customers.create')->name('customers.create');
        Route::get('/customers/export', [CustomerController::class, 'export'])
            ->middleware('permission:sales.customers.export')->name('customers.export');
        Route::get('/customers/short-code-suggest', [CustomerController::class, 'suggestShortCode'])
            ->middleware('permission:sales.customers.create')->name('customers.short-code-suggest');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])
            ->middleware('permission:sales.customers.view')->name('customers.show');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])
            ->middleware('permission:sales.customers.edit')->name('customers.edit');
        Route::get('/customers/{customer}/statement/export', [StatementController::class, 'export'])
            ->middleware('permission:sales.statements.export')->name('customers.statement.export');
        Route::get('/customers/{customer}/statement', [StatementController::class, 'show'])
            ->middleware('permission:sales.customers.view')->name('customers.statement');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('permission:sales.customers.create')->name('customers.store');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])
            ->middleware('permission:sales.customers.edit')->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('permission:sales.customers.delete')->name('customers.destroy');
        Route::get('/customers/{customer}/email', [CustomerController::class, 'emailForm'])
            ->middleware('permission:sales.customers.email')->name('customers.email');
        Route::post('/customers/{customer}/email', [CustomerController::class, 'sendEmail'])
            ->middleware('permission:sales.customers.email')->name('customers.email.send');

        Route::get('/quotes', [QuoteController::class, 'index'])
            ->middleware('permission:sales.quotes.view')->name('quotes.index');
        Route::get('/quotes/create', [QuoteController::class, 'create'])
            ->middleware('permission:sales.quotes.create')->name('quotes.create');
        Route::get('/quotes/{quote}/edit', [QuoteController::class, 'edit'])
            ->middleware('permission:sales.quotes.edit')->name('quotes.edit');
        Route::get('/quotes/export', [QuoteController::class, 'export'])
            ->middleware('permission:sales.quotes.export')->name('quotes.export');
        Route::post('/quotes', [QuoteController::class, 'store'])
            ->middleware('permission:sales.quotes.create')->name('quotes.store');
        Route::put('/quotes/{quote}', [QuoteController::class, 'update'])
            ->middleware('permission:sales.quotes.edit')->name('quotes.update');
        Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])
            ->middleware('permission:sales.quotes.delete')->name('quotes.destroy');
        Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convert'])
            ->middleware('permission:sales.quotes.convert')->name('quotes.convert');
        Route::patch('/quotes/{quote}/status', [QuoteController::class, 'updateStatus'])
            ->middleware('permission:sales.quotes.edit')->name('quotes.status');
        Route::get('/quotes/{quote}', [QuoteController::class, 'show'])
            ->middleware('permission:sales.quotes.view')->name('quotes.show');
        Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])
            ->middleware('permission:sales.quotes.view')->name('quotes.pdf');

        Route::get('/orders', [OrderController::class, 'index'])
            ->middleware('permission:sales.orders.view')->name('orders.index');
        Route::get('/orders/create', [OrderController::class, 'create'])
            ->middleware('permission:sales.orders.create')->name('orders.create');
        Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])
            ->middleware('permission:sales.orders.edit')->name('orders.edit');
        Route::get('/orders/export', [OrderController::class, 'export'])
            ->middleware('permission:sales.orders.export')->name('orders.export');
        Route::post('/orders', [OrderController::class, 'store'])
            ->middleware('permission:sales.orders.create')->name('orders.store');
        Route::put('/orders/{order}', [OrderController::class, 'update'])
            ->middleware('permission:sales.orders.edit')->name('orders.update');
        Route::delete('/orders/{order}', [OrderController::class, 'destroy'])
            ->middleware('permission:sales.orders.delete')->name('orders.destroy');
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm'])
            ->middleware('permission:sales.orders.confirm')->name('orders.confirm');
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])
            ->middleware('permission:sales.orders.update_status')->name('orders.status');
        Route::get('/orders/{order}', [OrderController::class, 'show'])
            ->middleware('permission:sales.orders.view')->name('orders.show');
        Route::get('/orders/{order}/pdf', [OrderController::class, 'pdf'])
            ->middleware('permission:sales.orders.view')->name('orders.pdf');

        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware('permission:sales.invoices.view')->name('invoices.index');
        Route::get('/invoices/create', [InvoiceController::class, 'create'])
            ->middleware('permission:sales.invoices.create')->name('invoices.create');
        Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
            ->middleware('permission:sales.invoices.edit')->name('invoices.edit');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware('permission:sales.invoices.view')->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
            ->middleware('permission:sales.invoices.view')->name('invoices.pdf');
        Route::get('/invoices/export', [InvoiceController::class, 'export'])
            ->middleware('permission:sales.invoices.export')->name('invoices.export');
        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->middleware('permission:sales.invoices.create')->name('invoices.store');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
            ->middleware('permission:sales.invoices.edit')->name('invoices.update');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
            ->middleware('permission:sales.invoices.delete')->name('invoices.destroy');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
            ->middleware('permission:sales.invoices.record_payment')->name('invoices.payments.store');

        Route::get('/payment-in', [PaymentController::class, 'index'])
            ->middleware('permission:sales.sales_payments.view')->name('sales_payments.index');
        Route::get('/payment-in/create', [PaymentController::class, 'create'])
            ->middleware('permission:sales.sales_payments.create')->name('sales_payments.create');
        Route::get('/payment-in/{payment}/edit', [PaymentController::class, 'edit'])
            ->middleware('permission:sales.sales_payments.edit')->name('sales_payments.edit');
        Route::get('/payment-in/{payment}/pdf', [PaymentController::class, 'pdf'])
            ->middleware('permission:sales.sales_payments.view')->name('sales_payments.pdf');
        Route::get('/payment-in/export', [PaymentController::class, 'export'])
            ->middleware('permission:sales.sales_payments.export')->name('sales_payments.export');
        Route::post('/payment-in', [PaymentController::class, 'store'])
            ->middleware('permission:sales.sales_payments.create')->name('sales_payments.store');
        Route::put('/payment-in/{payment}', [PaymentController::class, 'update'])
            ->middleware('permission:sales.sales_payments.edit')->name('sales_payments.update');
        Route::delete('/payment-in/{payment}', [PaymentController::class, 'destroy'])
            ->middleware('permission:sales.sales_payments.delete')->name('sales_payments.destroy');

        Route::get('/credit-notes', [CreditNoteController::class, 'index'])
            ->middleware('permission:sales.credit_notes.view')->name('credit_notes.index');
        Route::get('/credit-notes/create', [CreditNoteController::class, 'create'])
            ->middleware('permission:sales.credit_notes.create')->name('credit_notes.create');
        Route::get('/credit-notes/{creditNote}/edit', [CreditNoteController::class, 'edit'])
            ->middleware('permission:sales.credit_notes.edit')->name('credit_notes.edit');
        Route::get('/credit-notes/export', [CreditNoteController::class, 'export'])
            ->middleware('permission:sales.credit_notes.export')->name('credit_notes.export');
        Route::post('/credit-notes', [CreditNoteController::class, 'store'])
            ->middleware('permission:sales.credit_notes.create')->name('credit_notes.store');
        Route::put('/credit-notes/{creditNote}', [CreditNoteController::class, 'update'])
            ->middleware('permission:sales.credit_notes.edit')->name('credit_notes.update');
        Route::delete('/credit-notes/{creditNote}', [CreditNoteController::class, 'destroy'])
            ->middleware('permission:sales.credit_notes.delete')->name('credit_notes.destroy');
        Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show'])
            ->middleware('permission:sales.credit_notes.view')->name('credit_notes.show');
        Route::get('/credit-notes/{creditNote}/pdf', [CreditNoteController::class, 'pdf'])
            ->middleware('permission:sales.credit_notes.view')->name('credit_notes.pdf');

        Route::get('/delivery-notes', [DeliveryNoteController::class, 'index'])
            ->middleware('permission:sales.delivery_notes.view')->name('delivery_notes.index');
        Route::get('/delivery-notes/create', [DeliveryNoteController::class, 'create'])
            ->middleware('permission:sales.delivery_notes.create')->name('delivery_notes.create');
        Route::get('/delivery-notes/{deliveryNote}/edit', [DeliveryNoteController::class, 'edit'])
            ->middleware('permission:sales.delivery_notes.edit')->name('delivery_notes.edit');
        Route::get('/delivery-notes/export', [DeliveryNoteController::class, 'export'])
            ->middleware('permission:sales.delivery_notes.export')->name('delivery_notes.export');
        Route::post('/delivery-notes', [DeliveryNoteController::class, 'store'])
            ->middleware('permission:sales.delivery_notes.create')->name('delivery_notes.store');
        Route::put('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'update'])
            ->middleware('permission:sales.delivery_notes.edit')->name('delivery_notes.update');
        Route::delete('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'destroy'])
            ->middleware('permission:sales.delivery_notes.delete')->name('delivery_notes.destroy');
        Route::patch('/delivery-notes/{deliveryNote}/status', [DeliveryNoteController::class, 'updateStatus'])
            ->middleware('permission:sales.delivery_notes.update_status')->name('delivery_notes.status');
        Route::get('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'show'])
            ->middleware('permission:sales.delivery_notes.view')->name('delivery_notes.show');
        Route::get('/delivery-notes/{deliveryNote}/pdf', [DeliveryNoteController::class, 'pdf'])
            ->middleware('permission:sales.delivery_notes.view')->name('delivery_notes.pdf');

        Route::get('/recurring-invoices', [RecurringInvoiceController::class, 'index'])
            ->middleware('permission:sales.recurring_invoices.view')->name('recurring_invoices.index');
        Route::get('/recurring-invoices/create', [RecurringInvoiceController::class, 'create'])
            ->middleware('permission:sales.recurring_invoices.create')->name('recurring_invoices.create');
        Route::get('/recurring-invoices/{recurringInvoice}/edit', [RecurringInvoiceController::class, 'edit'])
            ->middleware('permission:sales.recurring_invoices.edit')->name('recurring_invoices.edit');
        Route::get('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'show'])
            ->middleware('permission:sales.recurring_invoices.view')->name('recurring_invoices.show');
        Route::get('/recurring-invoices/export', [RecurringInvoiceController::class, 'export'])
            ->middleware('permission:sales.recurring_invoices.export')->name('recurring_invoices.export');
        Route::post('/recurring-invoices', [RecurringInvoiceController::class, 'store'])
            ->middleware('permission:sales.recurring_invoices.create')->name('recurring_invoices.store');
        Route::put('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'update'])
            ->middleware('permission:sales.recurring_invoices.edit')->name('recurring_invoices.update');
        Route::delete('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'destroy'])
            ->middleware('permission:sales.recurring_invoices.delete')->name('recurring_invoices.destroy');

        Route::get('/tracking', [TrackingController::class, 'index'])
            ->middleware('permission:sales.tracking.view')->name('tracking.index');
        Route::patch('/tracking/orders/{order}', [TrackingController::class, 'updateOrderStatus'])
            ->middleware('permission:sales.tracking.update_status')->name('tracking.orders.status');
        Route::patch('/tracking/deliveries/{deliveryNote}', [TrackingController::class, 'updateDeliveryStatus'])
            ->middleware('permission:sales.tracking.update_status')->name('tracking.deliveries.status');

        Route::get('/withholding-tax-receipts', [WithholdingTaxReceiptController::class, 'index'])
            ->middleware('permission:sales.withholding_tax_receipts.view')->name('withholding_tax_receipts.index');
        Route::get('/withholding-tax-receipts/create', [WithholdingTaxReceiptController::class, 'create'])
            ->middleware('permission:sales.withholding_tax_receipts.create')->name('withholding_tax_receipts.create');
        Route::get('/withholding-tax-receipts/{withholdingTaxReceipt}/edit', [WithholdingTaxReceiptController::class, 'edit'])
            ->middleware('permission:sales.withholding_tax_receipts.edit')->name('withholding_tax_receipts.edit');
        Route::get('/withholding-tax-receipts/{withholdingTaxReceipt}', [WithholdingTaxReceiptController::class, 'show'])
            ->middleware('permission:sales.withholding_tax_receipts.view')->name('withholding_tax_receipts.show');
        Route::get('/withholding-tax-receipts/{withholdingTaxReceipt}/pdf', [WithholdingTaxReceiptController::class, 'pdf'])
            ->middleware('permission:sales.withholding_tax_receipts.view')->name('withholding_tax_receipts.pdf');
        Route::get('/withholding-tax-receipts/export', [WithholdingTaxReceiptController::class, 'export'])
            ->middleware('permission:sales.withholding_tax_receipts.export')->name('withholding_tax_receipts.export');
        Route::post('/withholding-tax-receipts', [WithholdingTaxReceiptController::class, 'store'])
            ->middleware('permission:sales.withholding_tax_receipts.create')->name('withholding_tax_receipts.store');
        Route::put('/withholding-tax-receipts/{withholdingTaxReceipt}', [WithholdingTaxReceiptController::class, 'update'])
            ->middleware('permission:sales.withholding_tax_receipts.edit')->name('withholding_tax_receipts.update');
        Route::delete('/withholding-tax-receipts/{withholdingTaxReceipt}', [WithholdingTaxReceiptController::class, 'destroy'])
            ->middleware('permission:sales.withholding_tax_receipts.delete')->name('withholding_tax_receipts.destroy');

        Route::get('/statements', [StatementController::class, 'index'])
            ->middleware('permission:sales.statements.view')->name('statements.index');
        Route::get('/statements/{customer}', [StatementController::class, 'show'])
            ->middleware('permission:sales.statements.view')->name('statements.show');
        Route::get('/statements/{customer}/export', [StatementController::class, 'export'])
            ->middleware('permission:sales.statements.export')->name('statements.export');

        Route::get('/reports/salesman', [\App\Http\Controllers\Sales\SalesReportController::class, 'index'])
            ->middleware('permission:sales.reports.view')->name('reports.salesman');
        Route::get('/reports/salesman/export', [\App\Http\Controllers\Sales\SalesReportController::class, 'export'])
            ->middleware('permission:sales.reports.view')->name('reports.salesman.export');
    });

    Route::prefix('inventory')->name('inventory.')->middleware('module:inventory')->group(function () {
        Route::get('/warehouses', [WarehouseController::class, 'index'])
            ->middleware('permission:inventory.warehouses.view')->name('warehouses.index');
        Route::get('/warehouses/create', [WarehouseController::class, 'create'])
            ->middleware('permission:inventory.warehouses.create')->name('warehouses.create');
        Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])
            ->middleware('permission:inventory.warehouses.edit')->name('warehouses.edit');
        Route::get('/warehouses/export', [WarehouseController::class, 'export'])
            ->middleware('permission:inventory.warehouses.export')->name('warehouses.export');
        Route::post('/warehouses', [WarehouseController::class, 'store'])
            ->middleware('permission:inventory.warehouses.create')->name('warehouses.store');
        Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])
            ->middleware('permission:inventory.warehouses.edit')->name('warehouses.update');
        Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])
            ->middleware('permission:inventory.warehouses.delete')->name('warehouses.destroy');

        Route::get('/items', [ItemController::class, 'index'])
            ->middleware('permission:inventory.items.view')->name('items.index');
        Route::get('/items/create', [ItemController::class, 'create'])
            ->middleware('permission:inventory.items.create')->name('items.create');
        Route::get('/items/export', [ItemController::class, 'export'])
            ->middleware('permission:inventory.items.export')->name('items.export');
        Route::get('/items/{item}/adjust', [ItemController::class, 'adjustForm'])
            ->middleware('permission:inventory.items.adjust_stock')->name('items.adjust');
        Route::get('/items/{item}', [ItemController::class, 'show'])
            ->middleware('permission:inventory.items.view')->name('items.show');
        Route::get('/items/{item}/edit', [ItemController::class, 'edit'])
            ->middleware('permission:inventory.items.edit')->name('items.edit');
        Route::post('/items', [ItemController::class, 'store'])
            ->middleware('permission:inventory.items.create')->name('items.store');
        Route::put('/items/{item}', [ItemController::class, 'update'])
            ->middleware('permission:inventory.items.edit')->name('items.update');
        Route::post('/items/{item}/adjust', [ItemController::class, 'adjust'])
            ->middleware('permission:inventory.items.adjust_stock')->name('items.adjust.store');
        Route::delete('/items/{item}', [ItemController::class, 'destroy'])
            ->middleware('permission:inventory.items.delete')->name('items.destroy');

        Route::get('/transfers', [TransferController::class, 'index'])
            ->middleware('permission:inventory.transfers.view')->name('transfers.index');
        Route::get('/transfers/create', [TransferController::class, 'create'])
            ->middleware('permission:inventory.transfers.create')->name('transfers.create');
        Route::get('/transfers/{transfer}/edit', [TransferController::class, 'edit'])
            ->middleware('permission:inventory.transfers.edit')->name('transfers.edit');
        Route::get('/transfers/export', [TransferController::class, 'export'])
            ->middleware('permission:inventory.transfers.export')->name('transfers.export');
        Route::post('/transfers', [TransferController::class, 'store'])
            ->middleware('permission:inventory.transfers.create')->name('transfers.store');
        Route::put('/transfers/{transfer}', [TransferController::class, 'update'])
            ->middleware('permission:inventory.transfers.edit')->name('transfers.update');
        Route::patch('/transfers/{transfer}/status', [TransferController::class, 'updateStatus'])
            ->middleware('permission:inventory.transfers.edit')->name('transfers.status');
        Route::delete('/transfers/{transfer}', [TransferController::class, 'destroy'])
            ->middleware('permission:inventory.transfers.delete')->name('transfers.destroy');

        Route::get('/write-offs', [WriteOffController::class, 'index'])
            ->middleware('permission:inventory.write_offs.view')->name('write_offs.index');
        Route::get('/write-offs/create', [WriteOffController::class, 'create'])
            ->middleware('permission:inventory.write_offs.create')->name('write_offs.create');
        Route::get('/write-offs/{writeOff}/edit', [WriteOffController::class, 'edit'])
            ->middleware('permission:inventory.write_offs.edit')->name('write_offs.edit');
        Route::get('/write-offs/export', [WriteOffController::class, 'export'])
            ->middleware('permission:inventory.write_offs.export')->name('write_offs.export');
        Route::post('/write-offs', [WriteOffController::class, 'store'])
            ->middleware('permission:inventory.write_offs.create')->name('write_offs.store');
        Route::put('/write-offs/{writeOff}', [WriteOffController::class, 'update'])
            ->middleware('permission:inventory.write_offs.edit')->name('write_offs.update');
        Route::patch('/write-offs/{writeOff}/status', [WriteOffController::class, 'updateStatus'])
            ->middleware('permission:inventory.write_offs.edit')->name('write_offs.status');
        Route::delete('/write-offs/{writeOff}', [WriteOffController::class, 'destroy'])
            ->middleware('permission:inventory.write_offs.delete')->name('write_offs.destroy');

        Route::get('/bill-of-materials', [BillOfMaterialController::class, 'index'])
            ->middleware('permission:inventory.bill_of_materials.view')->name('bill_of_materials.index');
        Route::get('/bill-of-materials/create', [BillOfMaterialController::class, 'create'])
            ->middleware('permission:inventory.bill_of_materials.create')->name('bill_of_materials.create');
        Route::get('/bill-of-materials/{billOfMaterial}/edit', [BillOfMaterialController::class, 'edit'])
            ->middleware('permission:inventory.bill_of_materials.edit')->name('bill_of_materials.edit');
        Route::get('/bill-of-materials/export', [BillOfMaterialController::class, 'export'])
            ->middleware('permission:inventory.bill_of_materials.export')->name('bill_of_materials.export');
        Route::post('/bill-of-materials', [BillOfMaterialController::class, 'store'])
            ->middleware('permission:inventory.bill_of_materials.create')->name('bill_of_materials.store');
        Route::put('/bill-of-materials/{billOfMaterial}', [BillOfMaterialController::class, 'update'])
            ->middleware('permission:inventory.bill_of_materials.edit')->name('bill_of_materials.update');
        Route::delete('/bill-of-materials/{billOfMaterial}', [BillOfMaterialController::class, 'destroy'])
            ->middleware('permission:inventory.bill_of_materials.delete')->name('bill_of_materials.destroy');

        Route::get('/production-orders', [ProductionOrderController::class, 'index'])
            ->middleware('permission:inventory.production_orders.view')->name('production_orders.index');
        Route::get('/production-orders/create', [ProductionOrderController::class, 'create'])
            ->middleware('permission:inventory.production_orders.create')->name('production_orders.create');
        Route::get('/production-orders/{order}/edit', [ProductionOrderController::class, 'edit'])
            ->middleware('permission:inventory.production_orders.edit')->name('production_orders.edit');
        Route::get('/production-orders/export', [ProductionOrderController::class, 'export'])
            ->middleware('permission:inventory.production_orders.export')->name('production_orders.export');
        Route::post('/production-orders', [ProductionOrderController::class, 'store'])
            ->middleware('permission:inventory.production_orders.create')->name('production_orders.store');
        Route::put('/production-orders/{order}', [ProductionOrderController::class, 'update'])
            ->middleware('permission:inventory.production_orders.edit')->name('production_orders.update');
        Route::patch('/production-orders/{order}/status', [ProductionOrderController::class, 'updateStatus'])
            ->middleware('permission:inventory.production_orders.update_status')->name('production_orders.status');
        Route::delete('/production-orders/{order}', [ProductionOrderController::class, 'destroy'])
            ->middleware('permission:inventory.production_orders.delete')->name('production_orders.destroy');

        // Incoming Shipments
        Route::get('/incoming-shipments', [IncomingShipmentController::class, 'index'])
            ->middleware('permission:inventory.incoming_shipments.view')->name('incoming_shipments.index');
        Route::get('/incoming-shipments/create', [IncomingShipmentController::class, 'create'])
            ->middleware('permission:inventory.incoming_shipments.create')->name('incoming_shipments.create');
        Route::get('/incoming-shipments/{shipment}', [IncomingShipmentController::class, 'show'])
            ->middleware('permission:inventory.incoming_shipments.view')->name('incoming_shipments.show');
        Route::get('/incoming-shipments/{shipment}/edit', [IncomingShipmentController::class, 'edit'])
            ->middleware('permission:inventory.incoming_shipments.edit')->name('incoming_shipments.edit');
        Route::get('/incoming-shipments/export', [IncomingShipmentController::class, 'export'])
            ->middleware('permission:inventory.incoming_shipments.export')->name('incoming_shipments.export');
        Route::post('/incoming-shipments', [IncomingShipmentController::class, 'store'])
            ->middleware('permission:inventory.incoming_shipments.create')->name('incoming_shipments.store');
        Route::put('/incoming-shipments/{shipment}', [IncomingShipmentController::class, 'update'])
            ->middleware('permission:inventory.incoming_shipments.edit')->name('incoming_shipments.update');
        Route::delete('/incoming-shipments/{shipment}', [IncomingShipmentController::class, 'destroy'])
            ->middleware('permission:inventory.incoming_shipments.delete')->name('incoming_shipments.destroy');
        Route::patch('/incoming-shipments/{shipment}/status', [IncomingShipmentController::class, 'updateStatus'])
            ->middleware('permission:inventory.incoming_shipments.receive')->name('incoming_shipments.status');
        Route::post('/incoming-shipments/{shipment}/approve', [IncomingShipmentController::class, 'approve'])
            ->middleware('permission:inventory.incoming_shipments.approve')->name('incoming_shipments.approve');
    });

    Route::prefix('suppliers')->name('suppliers.')->middleware('module:suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])
            ->middleware('permission:suppliers.suppliers.view')->name('suppliers.index');
        Route::get('/create', [SupplierController::class, 'create'])
            ->middleware('permission:suppliers.suppliers.create')->name('suppliers.create');
        Route::get('/export', [SupplierController::class, 'export'])
            ->middleware('permission:suppliers.suppliers.export')->name('suppliers.export');
        Route::get('/short-code-suggest', [SupplierController::class, 'suggestShortCode'])
            ->middleware('permission:suppliers.suppliers.create')->name('suppliers.short-code-suggest');
        Route::get('/{supplier}/edit', [SupplierController::class, 'edit'])
            ->middleware('permission:suppliers.suppliers.edit')->name('suppliers.edit');
        Route::post('/', [SupplierController::class, 'store'])
            ->middleware('permission:suppliers.suppliers.create')->name('suppliers.store');
        Route::put('/{supplier}', [SupplierController::class, 'update'])
            ->middleware('permission:suppliers.suppliers.edit')->name('suppliers.update');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])
            ->middleware('permission:suppliers.suppliers.delete')->name('suppliers.destroy');

        Route::get('/purchase-quotes', [PurchaseQuoteController::class, 'index'])
            ->middleware('permission:suppliers.purchase_quotes.view')->name('purchase_quotes.index');
        Route::get('/purchase-quotes/create', [PurchaseQuoteController::class, 'create'])
            ->middleware('permission:suppliers.purchase_quotes.create')->name('purchase_quotes.create');
        Route::get('/purchase-quotes/{quote}/edit', [PurchaseQuoteController::class, 'edit'])
            ->middleware('permission:suppliers.purchase_quotes.edit')->name('purchase_quotes.edit');
        Route::get('/purchase-quotes/export', [PurchaseQuoteController::class, 'export'])
            ->middleware('permission:suppliers.purchase_quotes.export')->name('purchase_quotes.export');
        Route::post('/purchase-quotes', [PurchaseQuoteController::class, 'store'])
            ->middleware('permission:suppliers.purchase_quotes.create')->name('purchase_quotes.store');
        Route::put('/purchase-quotes/{quote}', [PurchaseQuoteController::class, 'update'])
            ->middleware('permission:suppliers.purchase_quotes.edit')->name('purchase_quotes.update');
        Route::delete('/purchase-quotes/{quote}', [PurchaseQuoteController::class, 'destroy'])
            ->middleware('permission:suppliers.purchase_quotes.delete')->name('purchase_quotes.destroy');
        Route::post('/purchase-quotes/{quote}/convert', [PurchaseQuoteController::class, 'convert'])
            ->middleware('permission:suppliers.purchase_quotes.convert')->name('purchase_quotes.convert');
        Route::patch('/purchase-quotes/{quote}/status', [PurchaseQuoteController::class, 'updateStatus'])
            ->middleware('permission:suppliers.purchase_quotes.edit')->name('purchase_quotes.status');
        Route::get('/purchase-quotes/{quote}', [PurchaseQuoteController::class, 'show'])
            ->middleware('permission:suppliers.purchase_quotes.view')->name('purchase_quotes.show');
        Route::get('/purchase-quotes/{quote}/pdf', [PurchaseQuoteController::class, 'pdf'])
            ->middleware('permission:suppliers.purchase_quotes.view')->name('purchase_quotes.pdf');

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
            ->middleware('permission:suppliers.purchase_orders.view')->name('purchase_orders.index');
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])
            ->middleware('permission:suppliers.purchase_orders.create')->name('purchase_orders.create');
        Route::get('/purchase-orders/{order}/edit', [PurchaseOrderController::class, 'edit'])
            ->middleware('permission:suppliers.purchase_orders.edit')->name('purchase_orders.edit');
        Route::get('/purchase-orders/export', [PurchaseOrderController::class, 'export'])
            ->middleware('permission:suppliers.purchase_orders.export')->name('purchase_orders.export');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])
            ->middleware('permission:suppliers.purchase_orders.create')->name('purchase_orders.store');
        Route::put('/purchase-orders/{order}', [PurchaseOrderController::class, 'update'])
            ->middleware('permission:suppliers.purchase_orders.edit')->name('purchase_orders.update');
        Route::delete('/purchase-orders/{order}', [PurchaseOrderController::class, 'destroy'])
            ->middleware('permission:suppliers.purchase_orders.delete')->name('purchase_orders.destroy');
        Route::post('/purchase-orders/{order}/confirm', [PurchaseOrderController::class, 'confirm'])
            ->middleware('permission:suppliers.purchase_orders.confirm')->name('purchase_orders.confirm');
        Route::patch('/purchase-orders/{order}/status', [PurchaseOrderController::class, 'updateStatus'])
            ->middleware('permission:suppliers.purchase_orders.update_status')->name('purchase_orders.status');
        Route::get('/purchase-orders/{order}', [PurchaseOrderController::class, 'show'])
            ->middleware('permission:suppliers.purchase_orders.view')->name('purchase_orders.show');
        Route::get('/purchase-orders/{order}/pdf', [PurchaseOrderController::class, 'pdf'])
            ->middleware('permission:suppliers.purchase_orders.view')->name('purchase_orders.pdf');

        Route::get('/purchase-invoices', [PurchaseInvoiceController::class, 'index'])
            ->middleware('permission:suppliers.purchase_invoices.view')->name('purchase_invoices.index');
        Route::get('/purchase-invoices/create', [PurchaseInvoiceController::class, 'create'])
            ->middleware('permission:suppliers.purchase_invoices.create')->name('purchase_invoices.create');
        Route::get('/purchase-invoices/{invoice}/edit', [PurchaseInvoiceController::class, 'edit'])
            ->middleware('permission:suppliers.purchase_invoices.edit')->name('purchase_invoices.edit');
        Route::get('/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'show'])
            ->middleware('permission:suppliers.purchase_invoices.view')->name('purchase_invoices.show');
        Route::get('/purchase-invoices/{invoice}/pdf', [PurchaseInvoiceController::class, 'pdf'])
            ->middleware('permission:suppliers.purchase_invoices.view')->name('purchase_invoices.pdf');
        Route::get('/purchase-invoices/export', [PurchaseInvoiceController::class, 'export'])
            ->middleware('permission:suppliers.purchase_invoices.export')->name('purchase_invoices.export');
        Route::post('/purchase-invoices', [PurchaseInvoiceController::class, 'store'])
            ->middleware('permission:suppliers.purchase_invoices.create')->name('purchase_invoices.store');
        Route::put('/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'update'])
            ->middleware('permission:suppliers.purchase_invoices.edit')->name('purchase_invoices.update');
        Route::delete('/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'destroy'])
            ->middleware('permission:suppliers.purchase_invoices.delete')->name('purchase_invoices.destroy');
        Route::post('/purchase-invoices/{invoice}/payments', [PurchaseInvoiceController::class, 'recordPayment'])
            ->middleware('permission:suppliers.purchase_invoices.record_payment')->name('purchase_invoices.payments.store');

        Route::get('/debit-notes', [DebitNoteController::class, 'index'])
            ->middleware('permission:suppliers.debit_notes.view')->name('debit_notes.index');
        Route::get('/debit-notes/create', [DebitNoteController::class, 'create'])
            ->middleware('permission:suppliers.debit_notes.create')->name('debit_notes.create');
        Route::get('/debit-notes/{debitNote}/edit', [DebitNoteController::class, 'edit'])
            ->middleware('permission:suppliers.debit_notes.edit')->name('debit_notes.edit');
        Route::get('/debit-notes/export', [DebitNoteController::class, 'export'])
            ->middleware('permission:suppliers.debit_notes.export')->name('debit_notes.export');
        Route::post('/debit-notes', [DebitNoteController::class, 'store'])
            ->middleware('permission:suppliers.debit_notes.create')->name('debit_notes.store');
        Route::put('/debit-notes/{debitNote}', [DebitNoteController::class, 'update'])
            ->middleware('permission:suppliers.debit_notes.edit')->name('debit_notes.update');
        Route::delete('/debit-notes/{debitNote}', [DebitNoteController::class, 'destroy'])
            ->middleware('permission:suppliers.debit_notes.delete')->name('debit_notes.destroy');
        Route::get('/debit-notes/{debitNote}', [DebitNoteController::class, 'show'])
            ->middleware('permission:suppliers.debit_notes.view')->name('debit_notes.show');
        Route::get('/debit-notes/{debitNote}/pdf', [DebitNoteController::class, 'pdf'])
            ->middleware('permission:suppliers.debit_notes.view')->name('debit_notes.pdf');

        Route::get('/supplier-payments', [SupplierPaymentController::class, 'index'])
            ->middleware('permission:suppliers.supplier_payments.view')->name('supplier_payments.index');
        Route::get('/supplier-payments/create', [SupplierPaymentController::class, 'create'])
            ->middleware('permission:suppliers.supplier_payments.create')->name('supplier_payments.create');
        Route::get('/supplier-payments/{payment}/edit', [SupplierPaymentController::class, 'edit'])
            ->middleware('permission:suppliers.supplier_payments.edit')->name('supplier_payments.edit');
        Route::get('/supplier-payments/{payment}/pdf', [SupplierPaymentController::class, 'pdf'])
            ->middleware('permission:suppliers.supplier_payments.view')->name('supplier_payments.pdf');
        Route::get('/supplier-payments/export', [SupplierPaymentController::class, 'export'])
            ->middleware('permission:suppliers.supplier_payments.export')->name('supplier_payments.export');
        Route::post('/supplier-payments', [SupplierPaymentController::class, 'store'])
            ->middleware('permission:suppliers.supplier_payments.create')->name('supplier_payments.store');
        Route::put('/supplier-payments/{payment}', [SupplierPaymentController::class, 'update'])
            ->middleware('permission:suppliers.supplier_payments.edit')->name('supplier_payments.update');
        Route::delete('/supplier-payments/{payment}', [SupplierPaymentController::class, 'destroy'])
            ->middleware('permission:suppliers.supplier_payments.delete')->name('supplier_payments.destroy');

        Route::get('/ledger', [SupplierLedgerController::class, 'index'])
            ->middleware('permission:suppliers.supplier_ledger.view')->name('supplier_ledger.index');
        Route::get('/ledger/{supplier}', [SupplierLedgerController::class, 'show'])
            ->middleware('permission:suppliers.supplier_ledger.view')->name('supplier_ledger.show');
        Route::get('/ledger/{supplier}/export', [SupplierLedgerController::class, 'export'])
            ->middleware('permission:suppliers.supplier_ledger.export')->name('supplier_ledger.export');

        Route::get('/{supplier}', [SupplierController::class, 'show'])
            ->middleware('permission:suppliers.suppliers.view')->name('suppliers.show');
    });

    Route::prefix('banking')->name('banking.')->middleware('module:banking')->group(function () {
        Route::get('/accounts', [BankAccountController::class, 'index'])
            ->middleware('permission:banking.accounts.view')->name('accounts.index');
        Route::get('/accounts/create', [BankAccountController::class, 'create'])
            ->middleware('permission:banking.accounts.create')->name('accounts.create');
        Route::get('/accounts/export', [BankAccountController::class, 'export'])
            ->middleware('permission:banking.accounts.export')->name('accounts.export');
        Route::get('/accounts/{account}', [BankAccountController::class, 'show'])
            ->middleware('permission:banking.accounts.view')->name('accounts.show');
        Route::get('/accounts/{account}/edit', [BankAccountController::class, 'edit'])
            ->middleware('permission:banking.accounts.edit')->name('accounts.edit');
        Route::post('/accounts', [BankAccountController::class, 'store'])
            ->middleware('permission:banking.accounts.create')->name('accounts.store');
        Route::put('/accounts/{account}', [BankAccountController::class, 'update'])
            ->middleware('permission:banking.accounts.edit')->name('accounts.update');
        Route::delete('/accounts/{account}', [BankAccountController::class, 'destroy'])
            ->middleware('permission:banking.accounts.delete')->name('accounts.destroy');

        Route::get('/transactions', [BankTransactionController::class, 'index'])
            ->middleware('permission:banking.transactions.view')->name('transactions.index');
        Route::get('/transactions/create', [BankTransactionController::class, 'create'])
            ->middleware('permission:banking.transactions.create')->name('transactions.create');
        Route::get('/transactions/export', [BankTransactionController::class, 'export'])
            ->middleware('permission:banking.transactions.export')->name('transactions.export');
        Route::get('/transactions/{transaction}/edit', [BankTransactionController::class, 'edit'])
            ->middleware('permission:banking.transactions.edit')->name('transactions.edit');
        Route::post('/transactions', [BankTransactionController::class, 'store'])
            ->middleware('permission:banking.transactions.create')->name('transactions.store');
        Route::put('/transactions/{transaction}', [BankTransactionController::class, 'update'])
            ->middleware('permission:banking.transactions.edit')->name('transactions.update');
        Route::delete('/transactions/{transaction}', [BankTransactionController::class, 'destroy'])
            ->middleware('permission:banking.transactions.delete')->name('transactions.destroy');

        Route::get('/transfers', [BankTransferController::class, 'index'])
            ->middleware('permission:banking.transfers.view')->name('transfers.index');
        Route::get('/transfers/create', [BankTransferController::class, 'create'])
            ->middleware('permission:banking.transfers.create')->name('transfers.create');
        Route::get('/transfers/export', [BankTransferController::class, 'export'])
            ->middleware('permission:banking.transfers.export')->name('transfers.export');
        Route::get('/transfers/{transfer}/edit', [BankTransferController::class, 'edit'])
            ->middleware('permission:banking.transfers.edit')->name('transfers.edit');
        Route::post('/transfers', [BankTransferController::class, 'store'])
            ->middleware('permission:banking.transfers.create')->name('transfers.store');
        Route::put('/transfers/{transfer}', [BankTransferController::class, 'update'])
            ->middleware('permission:banking.transfers.edit')->name('transfers.update');
        Route::patch('/transfers/{transfer}/status', [BankTransferController::class, 'updateStatus'])
            ->middleware('permission:banking.transfers.edit')->name('transfers.status');
        Route::delete('/transfers/{transfer}', [BankTransferController::class, 'destroy'])
            ->middleware('permission:banking.transfers.delete')->name('transfers.destroy');

        Route::get('/reconciliations', [ReconciliationController::class, 'index'])
            ->middleware('permission:banking.reconciliations.view')->name('reconciliations.index');
        Route::get('/reconciliations/create', [ReconciliationController::class, 'create'])
            ->middleware('permission:banking.reconciliations.create')->name('reconciliations.create');
        Route::get('/reconciliations/export', [ReconciliationController::class, 'export'])
            ->middleware('permission:banking.reconciliations.export')->name('reconciliations.export');
        Route::get('/reconciliations/{reconciliation}/edit', [ReconciliationController::class, 'edit'])
            ->middleware('permission:banking.reconciliations.edit')->name('reconciliations.edit');
        Route::post('/reconciliations', [ReconciliationController::class, 'store'])
            ->middleware('permission:banking.reconciliations.create')->name('reconciliations.store');
        Route::put('/reconciliations/{reconciliation}', [ReconciliationController::class, 'update'])
            ->middleware('permission:banking.reconciliations.edit')->name('reconciliations.update');
        Route::patch('/reconciliations/{reconciliation}/status', [ReconciliationController::class, 'updateStatus'])
            ->middleware('permission:banking.reconciliations.edit')->name('reconciliations.status');
        Route::delete('/reconciliations/{reconciliation}', [ReconciliationController::class, 'destroy'])
            ->middleware('permission:banking.reconciliations.delete')->name('reconciliations.destroy');
    });

    Route::prefix('cash-flow')->name('cash_flow.')->middleware('module:cash_flow')->group(function () {
        Route::get('/', [CashFlowController::class, 'overview'])
            ->middleware('permission:cash_flow.overview.view')->name('overview');
        Route::get('/inflows', [CashFlowController::class, 'inflows'])
            ->middleware('permission:cash_flow.inflows.view')->name('inflows');
        Route::get('/inflows/export', [CashFlowController::class, 'inflowsExport'])
            ->middleware('permission:cash_flow.inflows.export')->name('inflows.export');
        Route::get('/outflows', [CashFlowController::class, 'outflows'])
            ->middleware('permission:cash_flow.outflows.view')->name('outflows');
        Route::get('/outflows/export', [CashFlowController::class, 'outflowsExport'])
            ->middleware('permission:cash_flow.outflows.export')->name('outflows.export');
        Route::get('/forecast', [CashFlowController::class, 'forecast'])
            ->middleware('permission:cash_flow.forecast.view')->name('forecast');
        Route::get('/forecast/export', [CashFlowController::class, 'forecastExport'])
            ->middleware('permission:cash_flow.forecast.export')->name('forecast.export');
        Route::get('/reports', [CashFlowController::class, 'reports'])
            ->middleware('permission:cash_flow.reports.view')->name('reports');
        Route::get('/reports/export', [CashFlowController::class, 'reportsExport'])
            ->middleware('permission:cash_flow.reports.export')->name('reports.export');
    });

    Route::prefix('accounting')->name('accounting.')->middleware('module:accounting')->group(function () {
        Route::get('/accounts', [AccountController::class, 'index'])
            ->middleware('permission:accounting.chart_of_accounts.view')->name('accounts.index');
        Route::get('/accounts/create', [AccountController::class, 'create'])
            ->middleware('permission:accounting.chart_of_accounts.create')->name('accounts.create');
        Route::get('/accounts/export', [AccountController::class, 'export'])
            ->middleware('permission:accounting.chart_of_accounts.export')->name('accounts.export');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])
            ->middleware('permission:accounting.chart_of_accounts.view')->name('accounts.show');
        Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])
            ->middleware('permission:accounting.chart_of_accounts.edit')->name('accounts.edit');
        Route::post('/accounts', [AccountController::class, 'store'])
            ->middleware('permission:accounting.chart_of_accounts.create')->name('accounts.store');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])
            ->middleware('permission:accounting.chart_of_accounts.edit')->name('accounts.update');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])
            ->middleware('permission:accounting.chart_of_accounts.delete')->name('accounts.destroy');

        Route::get('/journal', [JournalController::class, 'index'])
            ->middleware('permission:accounting.journal_entries.view')->name('journal.index');
        Route::get('/journal/create', [JournalController::class, 'create'])
            ->middleware('permission:accounting.journal_entries.create')->name('journal.create');
        Route::get('/journal/export', [JournalController::class, 'export'])
            ->middleware('permission:accounting.journal_entries.export')->name('journal.export');
        Route::get('/journal/{entry}', [JournalController::class, 'show'])
            ->middleware('permission:accounting.journal_entries.view')->name('journal.show');
        Route::get('/journal/{entry}/edit', [JournalController::class, 'edit'])
            ->middleware('permission:accounting.journal_entries.edit')->name('journal.edit');
        Route::post('/journal', [JournalController::class, 'store'])
            ->middleware('permission:accounting.journal_entries.create')->name('journal.store');
        Route::put('/journal/{entry}', [JournalController::class, 'update'])
            ->middleware('permission:accounting.journal_entries.edit')->name('journal.update');
        Route::post('/journal/{entry}/post', [JournalController::class, 'post'])
            ->middleware('permission:accounting.journal_entries.edit')->name('journal.post');
        Route::post('/journal/{entry}/void', [JournalController::class, 'void'])
            ->middleware('permission:accounting.journal_entries.edit')->name('journal.void');
        Route::delete('/journal/{entry}', [JournalController::class, 'destroy'])
            ->middleware('permission:accounting.journal_entries.delete')->name('journal.destroy');

        Route::get('/expense-claims', [ExpenseClaimController::class, 'index'])
            ->middleware('permission:accounting.expense_claims.view')->name('expense_claims.index');
        Route::get('/expense-claims/create', [ExpenseClaimController::class, 'create'])
            ->middleware('permission:accounting.expense_claims.create')->name('expense_claims.create');
        Route::get('/expense-claims/export', [ExpenseClaimController::class, 'export'])
            ->middleware('permission:accounting.expense_claims.export')->name('expense_claims.export');
        Route::get('/expense-claims/{claim}', [ExpenseClaimController::class, 'show'])
            ->middleware('permission:accounting.expense_claims.view')->name('expense_claims.show');
        Route::get('/expense-claims/{claim}/edit', [ExpenseClaimController::class, 'edit'])
            ->middleware('permission:accounting.expense_claims.edit')->name('expense_claims.edit');
        Route::post('/expense-claims', [ExpenseClaimController::class, 'store'])
            ->middleware('permission:accounting.expense_claims.create')->name('expense_claims.store');
        Route::put('/expense-claims/{claim}', [ExpenseClaimController::class, 'update'])
            ->middleware('permission:accounting.expense_claims.edit')->name('expense_claims.update');
        Route::patch('/expense-claims/{claim}/status', [ExpenseClaimController::class, 'updateStatus'])
            ->middleware('permission:accounting.expense_claims.edit')->name('expense_claims.status');
        Route::delete('/expense-claims/{claim}', [ExpenseClaimController::class, 'destroy'])
            ->middleware('permission:accounting.expense_claims.delete')->name('expense_claims.destroy');

        Route::get('/bills', [BillController::class, 'index'])
            ->middleware('permission:accounting.bills.view')->name('bills.index');
        Route::get('/bills/create', [BillController::class, 'create'])
            ->middleware('permission:accounting.bills.create')->name('bills.create');
        Route::get('/bills/export', [BillController::class, 'export'])
            ->middleware('permission:accounting.bills.export')->name('bills.export');
        Route::get('/bills/{bill}', [BillController::class, 'show'])
            ->middleware('permission:accounting.bills.view')->name('bills.show');
        Route::get('/bills/{bill}/edit', [BillController::class, 'edit'])
            ->middleware('permission:accounting.bills.edit')->name('bills.edit');
        Route::post('/bills', [BillController::class, 'store'])
            ->middleware('permission:accounting.bills.create')->name('bills.store');
        Route::put('/bills/{bill}', [BillController::class, 'update'])
            ->middleware('permission:accounting.bills.edit')->name('bills.update');
        Route::post('/bills/{bill}/payment', [BillController::class, 'recordPayment'])
            ->middleware('permission:accounting.bills.record_payment')->name('bills.payment');
        Route::patch('/bills/{bill}/status', [BillController::class, 'updateStatus'])
            ->middleware('permission:accounting.bills.edit')->name('bills.status');
        Route::delete('/bills/{bill}', [BillController::class, 'destroy'])
            ->middleware('permission:accounting.bills.delete')->name('bills.destroy');

        Route::get('/tax-returns', [TaxReturnController::class, 'index'])
            ->middleware('permission:accounting.tax_returns.view')->name('tax_returns.index');
        Route::get('/tax-returns/create', [TaxReturnController::class, 'create'])
            ->middleware('permission:accounting.tax_returns.create')->name('tax_returns.create');
        Route::get('/tax-returns/export', [TaxReturnController::class, 'export'])
            ->middleware('permission:accounting.tax_returns.export')->name('tax_returns.export');
        Route::get('/tax-returns/{tax}', [TaxReturnController::class, 'show'])
            ->middleware('permission:accounting.tax_returns.view')->name('tax_returns.show');
        Route::get('/tax-returns/{tax}/edit', [TaxReturnController::class, 'edit'])
            ->middleware('permission:accounting.tax_returns.edit')->name('tax_returns.edit');
        Route::post('/tax-returns', [TaxReturnController::class, 'store'])
            ->middleware('permission:accounting.tax_returns.create')->name('tax_returns.store');
        Route::put('/tax-returns/{tax}', [TaxReturnController::class, 'update'])
            ->middleware('permission:accounting.tax_returns.edit')->name('tax_returns.update');
        Route::patch('/tax-returns/{tax}/status', [TaxReturnController::class, 'updateStatus'])
            ->middleware('permission:accounting.tax_returns.edit')->name('tax_returns.status');
        Route::delete('/tax-returns/{tax}', [TaxReturnController::class, 'destroy'])
            ->middleware('permission:accounting.tax_returns.delete')->name('tax_returns.destroy');

        Route::get('/budgets', [BudgetController::class, 'index'])
            ->middleware('permission:accounting.budgeting.view')->name('budgets.index');
        Route::get('/budgets/create', [BudgetController::class, 'create'])
            ->middleware('permission:accounting.budgeting.create')->name('budgets.create');
        Route::get('/budgets/export', [BudgetController::class, 'export'])
            ->middleware('permission:accounting.budgeting.export')->name('budgets.export');
        Route::get('/budgets/{budget}', [BudgetController::class, 'show'])
            ->middleware('permission:accounting.budgeting.view')->name('budgets.show');
        Route::get('/budgets/{budget}/edit', [BudgetController::class, 'edit'])
            ->middleware('permission:accounting.budgeting.edit')->name('budgets.edit');
        Route::post('/budgets', [BudgetController::class, 'store'])
            ->middleware('permission:accounting.budgeting.create')->name('budgets.store');
        Route::put('/budgets/{budget}', [BudgetController::class, 'update'])
            ->middleware('permission:accounting.budgeting.edit')->name('budgets.update');
        Route::patch('/budgets/{budget}/status', [BudgetController::class, 'updateStatus'])
            ->middleware('permission:accounting.budgeting.edit')->name('budgets.status');
        Route::delete('/budgets/{budget}', [BudgetController::class, 'destroy'])
            ->middleware('permission:accounting.budgeting.delete')->name('budgets.destroy');
    });

    Route::prefix('employees')->name('employees.')->middleware('module:employees')->group(function () {
        Route::get('/departments', [DepartmentController::class, 'index'])
            ->middleware('permission:employees.departments.view')->name('departments.index');
        Route::get('/departments/create', [DepartmentController::class, 'create'])
            ->middleware('permission:employees.departments.create')->name('departments.create');
        Route::get('/departments/export', [DepartmentController::class, 'export'])
            ->middleware('permission:employees.departments.export')->name('departments.export');
        Route::get('/departments/{department}', [DepartmentController::class, 'show'])
            ->middleware('permission:employees.departments.view')->name('departments.show');
        Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])
            ->middleware('permission:employees.departments.edit')->name('departments.edit');
        Route::post('/departments', [DepartmentController::class, 'store'])
            ->middleware('permission:employees.departments.create')->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])
            ->middleware('permission:employees.departments.edit')->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])
            ->middleware('permission:employees.departments.delete')->name('departments.destroy');

        Route::get('/employees', [EmployeeController::class, 'index'])
            ->middleware('permission:employees.employees.view')->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])
            ->middleware('permission:employees.employees.create')->name('employees.create');
        Route::get('/employees/export', [EmployeeController::class, 'export'])
            ->middleware('permission:employees.employees.export')->name('employees.export');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])
            ->middleware('permission:employees.employees.view')->name('employees.show');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])
            ->middleware('permission:employees.employees.edit')->name('employees.edit');
        Route::post('/employees', [EmployeeController::class, 'store'])
            ->middleware('permission:employees.employees.create')->name('employees.store');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:employees.employees.edit')->name('employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware('permission:employees.employees.delete')->name('employees.destroy');

        Route::get('/attendance', [AttendanceController::class, 'index'])
            ->middleware('permission:employees.attendance.view')->name('attendance.index');
        Route::get('/attendance/export', [AttendanceController::class, 'export'])
            ->middleware('permission:employees.attendance.export')->name('attendance.export');
        Route::get('/attendance/report', [AttendanceReportController::class, 'index'])
            ->middleware('permission:employees.attendance.view')->name('attendance.report');
        Route::get('/attendance/report/export', [AttendanceReportController::class, 'export'])
            ->middleware('permission:employees.attendance.export')->name('attendance.report.export');
        Route::get('/attendance/rules', [AttendanceRulesController::class, 'edit'])
            ->middleware('permission:employees.attendance.edit')->name('attendance.rules');
        Route::put('/attendance/rules', [AttendanceRulesController::class, 'update'])
            ->middleware('permission:employees.attendance.edit')->name('attendance.rules.update');
        Route::post('/attendance/mark', [AttendanceController::class, 'mark'])
            ->middleware('permission:employees.attendance.mark')->name('attendance.mark');
        Route::patch('/attendance/{record}', [AttendanceController::class, 'update'])
            ->middleware('permission:employees.attendance.edit')->name('attendance.update');
        Route::delete('/attendance/{record}', [AttendanceController::class, 'destroy'])
            ->middleware('permission:employees.attendance.delete')->name('attendance.destroy');

        Route::get('/my-attendance', [MyAttendanceController::class, 'index'])
            ->middleware('permission:employees.my_attendance.view')->name('my_attendance.index');
        Route::post('/my-attendance/mark', [MyAttendanceController::class, 'mark'])
            ->middleware('permission:employees.my_attendance.mark')->name('my_attendance.mark');
        Route::get('/attendance/qr-code', [AttendanceController::class, 'qrCode'])
            ->middleware('permission:employees.attendance.view')->name('attendance.qr-code');
        Route::get('/attendance/qr-code/download', [AttendanceController::class, 'downloadQrCode'])
            ->middleware('permission:employees.attendance.view')->name('attendance.qr-code.download');

        Route::get('/salary-structures', [SalaryStructureController::class, 'index'])
            ->middleware('permission:employees.salary_structures.view')->name('salary_structures.index');
        Route::get('/salary-structures/create', [SalaryStructureController::class, 'create'])
            ->middleware('permission:employees.salary_structures.create')->name('salary_structures.create');
        Route::get('/salary-structures/export', [SalaryStructureController::class, 'export'])
            ->middleware('permission:employees.salary_structures.export')->name('salary_structures.export');
        Route::post('/salary-structures', [SalaryStructureController::class, 'store'])
            ->middleware('permission:employees.salary_structures.create')->name('salary_structures.store');
        Route::get('/salary-structures/{structure}/edit', [SalaryStructureController::class, 'edit'])
            ->middleware('permission:employees.salary_structures.edit')->name('salary_structures.edit');
        Route::put('/salary-structures/{structure}', [SalaryStructureController::class, 'update'])
            ->middleware('permission:employees.salary_structures.edit')->name('salary_structures.update');
        Route::delete('/salary-structures/{structure}', [SalaryStructureController::class, 'destroy'])
            ->middleware('permission:employees.salary_structures.delete')->name('salary_structures.destroy');

        Route::get('/payroll', [PayrollRunController::class, 'index'])
            ->middleware('permission:employees.payroll_runs.view')->name('payroll.index');
        Route::get('/payroll/create', [PayrollRunController::class, 'create'])
            ->middleware('permission:employees.payroll_runs.create')->name('payroll.create');
        Route::get('/payroll/{run}/export', [PayrollRunController::class, 'export'])
            ->middleware('permission:employees.payroll_runs.export')->name('payroll.export');
        Route::post('/payroll', [PayrollRunController::class, 'store'])
            ->middleware('permission:employees.payroll_runs.create')->name('payroll.store');
        Route::get('/payroll/{run}', [PayrollRunController::class, 'show'])
            ->middleware('permission:employees.payroll_runs.view')->name('payroll.show');
        Route::post('/payroll/{run}/generate', [PayrollRunController::class, 'generate'])
            ->middleware('permission:employees.payroll_runs.approve')->name('payroll.generate');
        Route::post('/payroll/{run}/mark-paid', [PayrollRunController::class, 'markPaid'])
            ->middleware('permission:employees.payroll_runs.approve')->name('payroll.mark-paid');
        Route::delete('/payroll/{run}', [PayrollRunController::class, 'destroy'])
            ->middleware('permission:employees.payroll_runs.delete')->name('payroll.destroy');

        Route::post('/employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])
            ->middleware('permission:employees.documents.create')->name('documents.store');
        Route::get('/documents/{document}/download', [EmployeeDocumentController::class, 'download'])
            ->middleware('permission:employees.documents.view')->name('documents.download');
        Route::delete('/documents/{document}', [EmployeeDocumentController::class, 'destroy'])
            ->middleware('permission:employees.documents.delete')->name('documents.destroy');
    });

    Route::prefix('visits')->name('visits.')->middleware('module:visits')->group(function () {
        Route::get('/', [VisitsController::class, 'index'])
            ->middleware('permission:visits.visits.view')->name('index');
        Route::get('/create', [VisitsController::class, 'create'])
            ->middleware('permission:visits.visits.create')->name('create');
        Route::get('/export', [VisitsController::class, 'export'])
            ->middleware('permission:visits.visits.export')->name('export');
        Route::post('/', [VisitsController::class, 'store'])
            ->middleware('permission:visits.visits.create')->name('store');
        Route::get('/map', [VisitMapController::class, 'index'])
            ->middleware('permission:visits.map_view.view')->name('map');
        Route::get('/map/export', [VisitMapController::class, 'export'])
            ->middleware('permission:visits.map_view.export')->name('map.export');
        Route::get('/{visit}', [VisitsController::class, 'show'])
            ->middleware('permission:visits.visits.view')->name('show');
        Route::get('/{visit}/edit', [VisitsController::class, 'edit'])
            ->middleware('permission:visits.visits.edit')->name('edit');
        Route::put('/{visit}', [VisitsController::class, 'update'])
            ->middleware('permission:visits.visits.edit')->name('update');
        Route::post('/{visit}/start', [VisitsController::class, 'start'])
            ->middleware('permission:visits.visits.edit')->name('start');
        Route::post('/{visit}/complete', [VisitsController::class, 'complete'])
            ->middleware('permission:visits.visits.edit')->name('complete');
        Route::post('/{visit}/cancel', [VisitsController::class, 'cancel'])
            ->middleware('permission:visits.visits.edit')->name('cancel');
        Route::delete('/{visit}', [VisitsController::class, 'destroy'])
            ->middleware('permission:visits.visits.delete')->name('destroy');

        Route::get('/{visit}/pitstops/export', [VisitPitStopController::class, 'export'])
            ->middleware('permission:visits.pit_stops.export')->name('pitstops.export');
        Route::post('/{visit}/pitstops', [VisitPitStopController::class, 'store'])
            ->middleware('permission:visits.pit_stops.create')->name('pitstops.store');
        Route::put('/{visit}/pitstops/{pitstop}', [VisitPitStopController::class, 'update'])
            ->middleware('permission:visits.pit_stops.edit')->name('pitstops.update');
        Route::delete('/{visit}/pitstops/{pitstop}', [VisitPitStopController::class, 'destroy'])
            ->middleware('permission:visits.pit_stops.delete')->name('pitstops.destroy');
        Route::get('/pitstops/{pitstop}/image', [VisitPitStopController::class, 'image'])
            ->middleware('permission:visits.pit_stops.view')->name('pitstops.image');
    });

    Route::prefix('capital-accounts')->name('capital.')->middleware('module:capital_accounts')->group(function () {
        Route::get('/contributions', [ContributionController::class, 'index'])
            ->middleware('permission:capital_accounts.contributions.view')->name('contributions.index');
        Route::get('/contributions/create', [ContributionController::class, 'create'])
            ->middleware('permission:capital_accounts.contributions.create')->name('contributions.create');
        Route::get('/contributions/export', [ContributionController::class, 'export'])
            ->middleware('permission:capital_accounts.contributions.export')->name('contributions.export');
        Route::post('/contributions', [ContributionController::class, 'store'])
            ->middleware('permission:capital_accounts.contributions.create')->name('contributions.store');
        Route::get('/contributions/{contribution}/edit', [ContributionController::class, 'edit'])
            ->middleware('permission:capital_accounts.contributions.edit')->name('contributions.edit');
        Route::put('/contributions/{contribution}', [ContributionController::class, 'update'])
            ->middleware('permission:capital_accounts.contributions.edit')->name('contributions.update');
        Route::delete('/contributions/{contribution}', [ContributionController::class, 'destroy'])
            ->middleware('permission:capital_accounts.contributions.delete')->name('contributions.destroy');

        Route::get('/drawings', [DrawingController::class, 'index'])
            ->middleware('permission:capital_accounts.drawings.view')->name('drawings.index');
        Route::get('/drawings/create', [DrawingController::class, 'create'])
            ->middleware('permission:capital_accounts.drawings.create')->name('drawings.create');
        Route::get('/drawings/export', [DrawingController::class, 'export'])
            ->middleware('permission:capital_accounts.drawings.export')->name('drawings.export');
        Route::post('/drawings', [DrawingController::class, 'store'])
            ->middleware('permission:capital_accounts.drawings.create')->name('drawings.store');
        Route::get('/drawings/{drawing}/edit', [DrawingController::class, 'edit'])
            ->middleware('permission:capital_accounts.drawings.edit')->name('drawings.edit');
        Route::put('/drawings/{drawing}', [DrawingController::class, 'update'])
            ->middleware('permission:capital_accounts.drawings.edit')->name('drawings.update');
        Route::delete('/drawings/{drawing}', [DrawingController::class, 'destroy'])
            ->middleware('permission:capital_accounts.drawings.delete')->name('drawings.destroy');

        Route::get('/equity', [EquityController::class, 'index'])
            ->middleware('permission:capital_accounts.equity.view')->name('equity.index');
        Route::get('/equity/export', [EquityController::class, 'export'])
            ->middleware('permission:capital_accounts.equity.export')->name('equity.export');

        Route::get('/statements', [CapitalStatementController::class, 'index'])
            ->middleware('permission:capital_accounts.statements.view')->name('statements.index');
        Route::get('/statements/export', [CapitalStatementController::class, 'export'])
            ->middleware('permission:capital_accounts.statements.export')->name('statements.export');
    });

    Route::prefix('fixed-assets')->name('fixed_assets.')->middleware('module:fixed_assets')->group(function () {
        Route::get('/assets', [AssetController::class, 'index'])
            ->middleware('permission:fixed_assets.assets.view')->name('assets.index');
        Route::get('/assets/create', [AssetController::class, 'create'])
            ->middleware('permission:fixed_assets.assets.create')->name('assets.create');
        Route::get('/assets/export', [AssetController::class, 'export'])
            ->middleware('permission:fixed_assets.assets.export')->name('assets.export');
        Route::post('/assets', [AssetController::class, 'store'])
            ->middleware('permission:fixed_assets.assets.create')->name('assets.store');
        Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])
            ->middleware('permission:fixed_assets.assets.edit')->name('assets.edit');
        Route::put('/assets/{asset}', [AssetController::class, 'update'])
            ->middleware('permission:fixed_assets.assets.edit')->name('assets.update');
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])
            ->middleware('permission:fixed_assets.assets.delete')->name('assets.destroy');

        Route::get('/depreciation', [DepreciationController::class, 'index'])
            ->middleware('permission:fixed_assets.depreciation.view')->name('depreciation.index');
        Route::post('/depreciation/run', [DepreciationController::class, 'run'])
            ->middleware('permission:fixed_assets.depreciation.run')->name('depreciation.run');
        Route::get('/depreciation/export', [DepreciationController::class, 'export'])
            ->middleware('permission:fixed_assets.depreciation.export')->name('depreciation.export');

        Route::get('/disposals', [DisposalController::class, 'index'])
            ->middleware('permission:fixed_assets.disposals.view')->name('disposals.index');
        Route::get('/disposals/create', [DisposalController::class, 'create'])
            ->middleware('permission:fixed_assets.disposals.create')->name('disposals.create');
        Route::get('/disposals/export', [DisposalController::class, 'export'])
            ->middleware('permission:fixed_assets.disposals.export')->name('disposals.export');
        Route::post('/disposals', [DisposalController::class, 'store'])
            ->middleware('permission:fixed_assets.disposals.create')->name('disposals.store');
        Route::get('/disposals/{disposal}/edit', [DisposalController::class, 'edit'])
            ->middleware('permission:fixed_assets.disposals.edit')->name('disposals.edit');
        Route::put('/disposals/{disposal}', [DisposalController::class, 'update'])
            ->middleware('permission:fixed_assets.disposals.edit')->name('disposals.update');
        Route::delete('/disposals/{disposal}', [DisposalController::class, 'destroy'])
            ->middleware('permission:fixed_assets.disposals.delete')->name('disposals.destroy');

        Route::get('/reports', [AssetReportController::class, 'index'])
            ->middleware('permission:fixed_assets.reports.view')->name('reports.index');
        Route::get('/reports/export', [AssetReportController::class, 'export'])
            ->middleware('permission:fixed_assets.reports.export')->name('reports.export');
    });

    Route::prefix('investments')->name('investments.')->middleware('module:investments')->group(function () {
        Route::get('/', [PortfolioController::class, 'index'])
            ->middleware('permission:investments.portfolio.view')->name('portfolio.index');
        Route::get('/create', [PortfolioController::class, 'create'])
            ->middleware('permission:investments.portfolio.create')->name('portfolio.create');
        Route::get('/export', [PortfolioController::class, 'export'])
            ->middleware('permission:investments.portfolio.export')->name('portfolio.export');
        Route::post('/', [PortfolioController::class, 'store'])
            ->middleware('permission:investments.portfolio.create')->name('portfolio.store');
        Route::get('/{investment}/edit', [PortfolioController::class, 'edit'])
            ->middleware('permission:investments.portfolio.edit')->name('portfolio.edit');
        Route::put('/{investment}', [PortfolioController::class, 'update'])
            ->middleware('permission:investments.portfolio.edit')->name('portfolio.update');
        Route::delete('/{investment}', [PortfolioController::class, 'destroy'])
            ->middleware('permission:investments.portfolio.delete')->name('portfolio.destroy');

        Route::get('/transactions', [TransactionController::class, 'index'])
            ->middleware('permission:investments.transactions.view')->name('transactions.index');
        Route::get('/transactions/create', [TransactionController::class, 'create'])
            ->middleware('permission:investments.transactions.create')->name('transactions.create');
        Route::get('/transactions/export', [TransactionController::class, 'export'])
            ->middleware('permission:investments.transactions.export')->name('transactions.export');
        Route::post('/transactions', [TransactionController::class, 'store'])
            ->middleware('permission:investments.transactions.create')->name('transactions.store');
        Route::get('/transactions/{transaction}/edit', [TransactionController::class, 'edit'])
            ->middleware('permission:investments.transactions.edit')->name('transactions.edit');
        Route::put('/transactions/{transaction}', [TransactionController::class, 'update'])
            ->middleware('permission:investments.transactions.edit')->name('transactions.update');
        Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy'])
            ->middleware('permission:investments.transactions.delete')->name('transactions.destroy');

        Route::get('/returns', [ReturnController::class, 'index'])
            ->middleware('permission:investments.returns.view')->name('returns.index');
        Route::get('/returns/export', [ReturnController::class, 'export'])
            ->middleware('permission:investments.returns.export')->name('returns.export');

        Route::get('/dividends', [DividendController::class, 'index'])
            ->middleware('permission:investments.dividends.view')->name('dividends.index');
        Route::get('/dividends/create', [DividendController::class, 'create'])
            ->middleware('permission:investments.dividends.create')->name('dividends.create');
        Route::get('/dividends/export', [DividendController::class, 'export'])
            ->middleware('permission:investments.dividends.export')->name('dividends.export');
        Route::post('/dividends', [DividendController::class, 'store'])
            ->middleware('permission:investments.dividends.create')->name('dividends.store');
        Route::get('/dividends/{dividend}/edit', [DividendController::class, 'edit'])
            ->middleware('permission:investments.dividends.edit')->name('dividends.edit');
        Route::put('/dividends/{dividend}', [DividendController::class, 'update'])
            ->middleware('permission:investments.dividends.edit')->name('dividends.update');
        Route::delete('/dividends/{dividend}', [DividendController::class, 'destroy'])
            ->middleware('permission:investments.dividends.delete')->name('dividends.destroy');

        Route::get('/reports', [InvestmentReportController::class, 'index'])
            ->middleware('permission:investments.reports.view')->name('reports.index');
        Route::get('/reports/export', [InvestmentReportController::class, 'export'])
            ->middleware('permission:investments.reports.export')->name('reports.export');
    });

    Route::prefix('pos')->name('pos.')->middleware('module:pos')->group(function () {
        Route::get('/', [SaleScreenController::class, 'index'])
            ->middleware('permission:pos.sale_screen.view')->name('sale_screen.index');
        Route::post('/', [SaleScreenController::class, 'store'])
            ->middleware('permission:pos.sale_screen.use')->name('sale_screen.store');

        Route::get('/payment-methods', [PosPaymentMethodController::class, 'index'])
            ->middleware('permission:pos.payment_methods.view')->name('payment_methods.index');
        Route::get('/payment-methods/create', [PosPaymentMethodController::class, 'create'])
            ->middleware('permission:pos.payment_methods.create')->name('payment_methods.create');
        Route::get('/payment-methods/export', [PosPaymentMethodController::class, 'export'])
            ->middleware('permission:pos.payment_methods.export')->name('payment_methods.export');
        Route::post('/payment-methods', [PosPaymentMethodController::class, 'store'])
            ->middleware('permission:pos.payment_methods.create')->name('payment_methods.store');
        Route::get('/payment-methods/{paymentMethod}/edit', [PosPaymentMethodController::class, 'edit'])
            ->middleware('permission:pos.payment_methods.edit')->name('payment_methods.edit');
        Route::put('/payment-methods/{paymentMethod}', [PosPaymentMethodController::class, 'update'])
            ->middleware('permission:pos.payment_methods.edit')->name('payment_methods.update');
        Route::delete('/payment-methods/{paymentMethod}', [PosPaymentMethodController::class, 'destroy'])
            ->middleware('permission:pos.payment_methods.delete')->name('payment_methods.destroy');

        Route::get('/shifts', [ShiftController::class, 'index'])
            ->middleware('permission:pos.shifts.view')->name('shifts.index');
        Route::post('/shifts', [ShiftController::class, 'open'])
            ->middleware('permission:pos.shifts.open')->name('shifts.open');
        Route::get('/shifts/export', [ShiftController::class, 'export'])
            ->middleware('permission:pos.shifts.export')->name('shifts.export');
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])
            ->middleware('permission:pos.shifts.close')->name('shifts.close');

        Route::get('/receipts', [ReceiptController::class, 'index'])
            ->middleware('permission:pos.receipts.view')->name('receipts.index');
        Route::get('/receipts/export', [ReceiptController::class, 'export'])
            ->middleware('permission:pos.receipts.view')->name('receipts.export');
        Route::get('/receipts/{sale}', [ReceiptController::class, 'show'])
            ->middleware('permission:pos.receipts.view')->name('receipts.show');

        Route::get('/reconciliations', [PosReconciliationController::class, 'index'])
            ->middleware('permission:pos.till_reconciliation.view')->name('reconciliations.index');
        Route::get('/reconciliations/create', [PosReconciliationController::class, 'create'])
            ->middleware('permission:pos.till_reconciliation.create')->name('reconciliations.create');
        Route::get('/reconciliations/export', [PosReconciliationController::class, 'export'])
            ->middleware('permission:pos.till_reconciliation.export')->name('reconciliations.export');
        Route::post('/reconciliations', [PosReconciliationController::class, 'store'])
            ->middleware('permission:pos.till_reconciliation.create')->name('reconciliations.store');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/company', [CompanyController::class, 'edit'])
            ->middleware('permission:settings.company.view')->name('company');
        Route::put('/company', [CompanyController::class, 'updateCompany'])
            ->middleware('permission:settings.company.manage')->name('company.update');
        Route::put('/branding', [CompanyController::class, 'updateBranding'])
            ->middleware('permission:settings.branding.manage')->name('branding.update');
        Route::delete('/branding', [CompanyController::class, 'removeBranding'])
            ->middleware('permission:settings.branding.manage')->name('branding.remove');
        Route::put('/notifications', [CompanyController::class, 'updateNotifications'])
            ->middleware('permission:settings.notifications.manage')->name('notifications.update');
        Route::get('/notification-rules', [NotificationRuleController::class, 'index'])
            ->middleware('permission:settings.notifications.view')->name('notification-rules');
        Route::post('/notification-rules', [NotificationRuleController::class, 'store'])
            ->middleware('permission:settings.notifications.manage')->name('notification-rules.store');
        Route::put('/notification-rules/{rule}', [NotificationRuleController::class, 'update'])
            ->middleware('permission:settings.notifications.manage')->name('notification-rules.update');
        Route::put('/notification-rules/{rule}/toggle', [NotificationRuleController::class, 'toggle'])
            ->middleware('permission:settings.notifications.manage')->name('notification-rules.toggle');
        Route::delete('/notification-rules/{rule}', [NotificationRuleController::class, 'destroy'])
            ->middleware('permission:settings.notifications.manage')->name('notification-rules.destroy');

        Route::get('/modules', [ModuleController::class, 'index'])
            ->middleware('permission:settings.modules.view')->name('modules');
        Route::put('/modules/{module}', [ModuleController::class, 'update'])
            ->middleware('permission:settings.modules.manage')->name('modules.update');

        Route::get('/currencies', [CurrencyController::class, 'index'])
            ->middleware('permission:settings.currencies.view')->name('currencies');
        Route::put('/currencies', [CurrencyController::class, 'update'])
            ->middleware('permission:settings.currencies.manage')->name('currencies.update');
        Route::middleware('permission:settings.users.view')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        });
        Route::middleware('permission:settings.users.manage')->group(function () {
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

        Route::middleware('permission:settings.roles.view')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        });
        Route::middleware('permission:settings.roles.manage')->group(function () {
            Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::get('/audit-log', [AuditLogController::class, 'index'])
            ->middleware('permission:settings.audit.view')->name('audit-log');
        Route::get('/audit-log/export', [AuditLogController::class, 'export'])
            ->middleware('permission:settings.audit.export')->name('audit-log.export');

        Route::get('/backups', [BackupController::class, 'index'])
            ->middleware('permission:settings.backup.view')->name('backups');
        Route::post('/backups', [BackupController::class, 'create'])
            ->middleware('permission:settings.backup.manage')->name('backups.create');
        Route::post('/backups/restore', [BackupController::class, 'restore'])
            ->middleware('permission:settings.backup.manage')->name('backups.restore');
        Route::get('/backups/{file}/download', [BackupController::class, 'download'])
            ->middleware('permission:settings.backup.manage')->name('backups.download');
        Route::delete('/backups/{file}', [BackupController::class, 'destroy'])
            ->middleware('permission:settings.backup.manage')->name('backups.destroy');

        Route::get('/mail', [MailSettingsController::class, 'edit'])
            ->middleware('permission:settings.mail.view')->name('mail');
        Route::put('/mail', [MailSettingsController::class, 'update'])
            ->middleware('permission:settings.mail.manage')->name('mail.update');
        Route::post('/mail/test', [MailSettingsController::class, 'test'])
            ->middleware('permission:settings.mail.manage')->name('mail.test');

        Route::get('/subscription', [SubscriptionController::class, 'index'])
            ->middleware('permission:settings.subscription.view')->name('subscription');
        Route::post('/subscription', [SubscriptionController::class, 'activate'])
            ->middleware('permission:settings.subscription.manage')->name('subscription.activate');
        Route::delete('/subscription', [SubscriptionController::class, 'deactivate'])
            ->middleware('permission:settings.subscription.manage')->name('subscription.deactivate');

        Route::middleware('permission:settings.templates.view')->group(function () {
            Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
            Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
            Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
        });
        Route::middleware('permission:settings.templates.manage')->group(function () {
            Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
            Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
            Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        });

        Route::middleware('permission:settings.discount_rules.view')->group(function () {
            Route::get('/discount-rules', [DiscountRuleController::class, 'index'])->name('discount-rules.index');
            Route::get('/discount-rules/create', [DiscountRuleController::class, 'create'])->name('discount-rules.create');
            Route::get('/discount-rules/{discountRule}/edit', [DiscountRuleController::class, 'edit'])->name('discount-rules.edit');
        });
        Route::middleware('permission:settings.discount_rules.manage')->group(function () {
            Route::post('/discount-rules', [DiscountRuleController::class, 'store'])->name('discount-rules.store');
            Route::put('/discount-rules/{discountRule}', [DiscountRuleController::class, 'update'])->name('discount-rules.update');
            Route::delete('/discount-rules/{discountRule}', [DiscountRuleController::class, 'destroy'])->name('discount-rules.destroy');
        });
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
