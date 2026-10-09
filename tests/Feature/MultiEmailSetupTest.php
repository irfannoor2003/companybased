<?php

namespace Tests\Feature;

use App\Mail\PurchaseOrderMailable;
use App\Mail\SalesInvoiceMailable;
use App\Mail\SalesQuoteMailable;
use App\Models\PurchaseOrder;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesQuote;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Support\MailIdentity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MultiEmailSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();

        // CheckSubscription blocks every non-Super-Admin route when no package is
        // active, returning the errors.subscription-expired view with a 403. The
        // three send-document tests act as Admin -- a role that does hold the
        // business view permissions -- so without a subscription they were refused
        // by this middleware before the permission one was ever reached.
        Subscription::create([
            'plan_name' => 'Test Plan',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();
    }

    /**
     * Super Admin holds only settings.* permissions by design -- it configures the
     * installation and is explicitly not a company role. The send-document routes
     * are gated on the business module view permissions, so a Super Admin gets a
     * 403 there and that is correct behaviour, not a bug.
     *
     * Admin is the role that owns those business modules, so anything that acts on
     * a quote, invoice or purchase order runs as Admin.
     */
    private function admin(): User
    {
        return User::where('email', 'admin@nexosdigital.test')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 465,
            'username' => 'noreply@example.com',
            'password' => 'secret123',
            'encryption' => 'ssl',
            'system_from_address' => 'noreply@example.com',
            'system_from_name' => 'Nexos Digital',
            'reply_to_address' => 'info@example.com',
            'reply_to_name' => 'Nexos Support',
            'personal_from_address' => 'info@example.com',
            'personal_from_name' => 'Nexos Digital',
            'personal_use_shared_address' => '1',
        ], $overrides);
    }

    public function test_it_saves_accounts_and_sales_identities_and_credentials(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->basePayload([
                'accounts_from_address' => 'billing@example.com',
                'accounts_from_name' => 'Nexos Billing',
                'accounts_username' => 'billing@example.com',
                'accounts_password' => 'billing-secret',
                'sales_from_address' => 'sales@example.com',
                'sales_from_name' => 'Nexos Sales',
                'sales_use_salesman_address' => '1',
                'sales_username' => 'sales@example.com',
                'sales_password' => 'sales-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('billing@example.com', MailIdentity::accountsFromAddress());
        $this->assertSame('Nexos Billing', MailIdentity::accountsFromName());
        $this->assertSame('smtp_accounts', MailIdentity::accountsMailerName());

        $this->assertSame('sales@example.com', Setting::get('mail.sales_from_address'));
        $this->assertSame('Nexos Sales', MailIdentity::salesFromName());
        $this->assertSame('smtp_sales', MailIdentity::salesMailerName());
        $this->assertTrue(MailIdentity::usesSalesmanAddress());
    }

    public function test_sales_identity_resolves_salesman_details_when_enabled(): void
    {
        Setting::setMany([
            'mail.sales_from_address' => 'sales@example.com',
            'mail.sales_from_name' => 'Nexos Sales',
            'mail.sales_use_salesman_address' => '1',
        ]);

        $salesman = User::factory()->create([
            'name' => 'John Rep',
            'email' => 'john.rep@example.com',
        ]);

        $this->assertSame('john.rep@example.com', MailIdentity::salesFromAddress($salesman));
        $this->assertSame('John Rep', MailIdentity::salesFromName($salesman));

        // When no salesman is assigned, it falls back to general sales email
        $this->assertSame('sales@example.com', MailIdentity::salesFromAddress(null));
        $this->assertSame('Nexos Sales', MailIdentity::salesFromName(null));
    }

    public function test_it_rejects_incomplete_accounts_mailbox_credentials(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->basePayload([
                'accounts_username' => 'billing@example.com',
                'accounts_password' => '',
            ]))
            ->assertSessionHasErrors('accounts_password');
    }

    public function test_it_rejects_incomplete_sales_mailbox_credentials(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->basePayload([
                'sales_username' => '',
                'sales_password' => 'secret-without-user',
            ]))
            ->assertSessionHasErrors('sales_username');
    }

    public function test_can_email_sales_invoice(): void
    {
        $customer = SalesCustomer::create([
            'code' => 'C-INV',
            'company_name' => 'Acme Corp',
            'email' => 'finance@acme.test',
        ]);

        $invoice = SalesInvoice::create([
            'number' => 'INV-2026-0001',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'total' => 1250.00,
        ]);

        $this->actingAs($this->admin())
            ->post("/sales/invoices/{$invoice->id}/send-email", [
                'recipient_email' => 'finance@acme.test',
                'message' => 'Please find attached your invoice.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Mail::assertQueued(SalesInvoiceMailable::class, function ($mail) use ($invoice) {
            return $mail->hasTo('finance@acme.test') && $mail->invoice->id === $invoice->id;
        });
    }

    public function test_can_email_sales_quote(): void
    {
        $customer = SalesCustomer::create([
            'code' => 'C-QTE',
            'company_name' => 'Beta Ltd',
            'email' => 'buyer@beta.test',
        ]);

        $quote = SalesQuote::create([
            'number' => 'QTE-2026-0001',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'total' => 500.00,
        ]);

        $this->actingAs($this->admin())
            ->post("/sales/quotes/{$quote->id}/send-email", [
                'recipient_email' => 'buyer@beta.test',
                'message' => 'Here is your official quotation.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Mail::assertQueued(SalesQuoteMailable::class, function ($mail) use ($quote) {
            return $mail->hasTo('buyer@beta.test') && $mail->quote->id === $quote->id;
        });
    }

    public function test_can_email_purchase_order(): void
    {
        $supplier = Supplier::create([
            'code' => 'SUP-001',
            'company_name' => 'Industrial Parts Co',
            'email' => 'orders@parts.test',
        ]);

        $order = PurchaseOrder::create([
            'number' => 'PO-2026-0001',
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'total' => 3400.00,
        ]);

        $this->actingAs($this->admin())
            ->post("/suppliers/purchase-orders/{$order->id}/send-email", [
                'recipient_email' => 'orders@parts.test',
                'message' => 'Please confirm order dispatch.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Mail::assertQueued(PurchaseOrderMailable::class, function ($mail) use ($order) {
            return $mail->hasTo('orders@parts.test') && $mail->order->id === $order->id;
        });
    }

    /**
     * The counterpart to the three tests above.
     *
     * Super Admin configures the installation and manages the subscription; it is
     * not a company role and deliberately holds only settings.* permissions. It
     * therefore cannot send a customer's invoice, quote or purchase order.
     *
     * This is asserted rather than left implicit because the tempting "fix" for a
     * 403 here is to add business view permissions to RolesSeeder, which would
     * quietly turn Super Admin into a back-door operator on every sales and
     * purchasing screen.
     */
    public function test_super_admin_cannot_send_business_documents(): void
    {
        $customer = SalesCustomer::create([
            'code' => 'C-SU',
            'company_name' => 'Gamma Inc',
            'email' => 'ap@gamma.test',
        ]);

        $invoice = SalesInvoice::create([
            'number' => 'INV-2026-0900',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'total' => 100.00,
        ]);

        $this->actingAs($this->superAdmin())
            ->post("/sales/invoices/{$invoice->id}/send-email", [
                'recipient_email' => 'ap@gamma.test',
            ])
            ->assertForbidden();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }
}
