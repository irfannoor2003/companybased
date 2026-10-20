<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Mail\CustomerEmail;
use App\Mail\CustomerWelcome;
use App\Models\PriceList;
use App\Models\SalesCustomer;
use App\Support\ExportsCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $customers = SalesCustomer::query()
            ->withCount(['quotes', 'orders', 'invoices'])
            ->with([
                'invoices' => fn ($q) => $q->whereIn('status', ['sent', 'partially_paid', 'overdue'])->select('id', 'customer_id', 'total', 'status'),
                'payments' => fn ($q) => $q->select('id', 'customer_id', 'amount'),
                'creditNotes' => fn ($q) => $q->select('id', 'customer_id', 'applied_amount'),
            ])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('company_name')
            ->paginate(20)
            ->withQueryString();

        return view('sales.customers.index', compact('customers'));
    }

    public function create(): View
    {
        $priceLists = PriceList::query()->orderBy('name')->get();

        return view('sales.customers.create', compact('priceLists'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $customer = SalesCustomer::create($this->attributes($request, $data));

        $this->sendWelcomeEmail($customer);

        return redirect()->route('sales.customers.show', $customer)
            ->with('toasts', [['type' => 'success', 'message' => "Customer \"{$customer->company_name}\" created."]]);
    }

    /**
     * Let the customer know they are now on file. Queued, so a slow or
     * unreachable mail server never blocks the create request.
     *
     * Still wrapped, because "queued" is not the same as "cannot throw":
     * resolving the mailer builds the transport eagerly in order to dispatch
     * to the queue, so a misconfigured mail server raised out of `->queue()`
     * itself and failed the create request — after the customer was already
     * committed. That reported a 500 for a record that had in fact saved, and
     * a retry then tripped the unique constraint on the short code or email.
     * A welcome email is never worth a failed customer record.
     */
    private function sendWelcomeEmail(SalesCustomer $customer): void
    {
        if (! filled($customer->email)) {
            return;
        }

        try {
            Mail::to($customer->email)->queue(new CustomerWelcome($customer));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function show(SalesCustomer $customer): View
    {
        $customer->load([
            'quotes' => fn ($q) => $q->latest('issue_date')->limit(5),
            'orders' => fn ($q) => $q->latest('issue_date')->limit(5),
            'invoices' => fn ($q) => $q->latest('issue_date')->limit(5),
        ]);

        return view('sales.customers.show', compact('customer'));
    }

    public function edit(SalesCustomer $customer): View
    {
        $priceLists = PriceList::query()->orderBy('name')->get();

        return view('sales.customers.edit', compact('customer', 'priceLists'));
    }

    public function update(Request $request, SalesCustomer $customer): RedirectResponse
    {
        $data = $this->validateData($request, $customer->id);

        $customer->update($this->attributes($request, $data));

        return back()->with('toasts', [['type' => 'success', 'message' => "Customer \"{$customer->company_name}\" updated."]]);
    }

    /**
     * The writable customer attributes, shared by store and update so the two
     * cannot drift apart.
     */
    private function attributes(Request $request, array $data): array
    {
        return [
            'company_name' => $data['company_name'],
            'short_code' => $data['short_code'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'price_list_id' => $data['price_list_id'] ?? null,
            'credit_limit' => $data['credit_limit'] ?? 0,
            'opening_balance' => $this->signedOpeningBalance($data),
            'opening_balance_date' => $this->signedOpeningBalance($data) == 0.0
                ? null
                : ($data['opening_balance_date'] ?? null),
            'currency' => $data['currency'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * The opening balance as a signed figure.
     *
     * The form takes a magnitude plus a direction, because "the customer already
     * owes us 500" and "the customer is 500 in credit with us" are both entered
     * as 500 and are distinguished only by intent. Collapsing the two into one
     * signed value at the boundary means the direction can never be stored
     * inconsistently with the amount.
     */
    private function signedOpeningBalance(array $data): float
    {
        $amount = round((float) ($data['opening_balance'] ?? 0), 2);

        if ($amount == 0.0) {
            return 0.0;
        }

        $direction = $data['opening_balance_direction'] ?? null;

        if (! filled($direction)) {
            return 0.0;
        }

        return $direction === 'credit' ? -$amount : $amount;
    }

    public function destroy(SalesCustomer $customer): RedirectResponse
    {
        $name = $customer->company_name;
        $customer->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Customer \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $customers = SalesCustomer::query()
            ->withCount(['quotes', 'orders', 'invoices'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('company_name')
            ->get();

        return $this->streamCsv('customers-'.now()->format('Y-m-d').'.csv', ['ID', 'Company', 'Short code', 'Contact', 'Email', 'Phone', 'City', 'Country', 'Tax no.', 'Credit limit', 'Balance', 'Status'], $customers->map(fn (SalesCustomer $c) => [
            $c->id,
            $c->company_name,
            $c->short_code,
            $c->contact_name,
            $c->email,
            $c->phone,
            $c->city,
            $c->country,
            $c->tax_number,
            $c->credit_limit,
            $c->balance(),
            $c->is_active ? 'Active' : 'Inactive',
        ]));
    }

    public function suggestShortCode(Request $request): JsonResponse
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $request->input('name', '')), 0, 8)) ?: 'CUST';

        $existing = SalesCustomer::query()
            ->where('short_code', 'like', $prefix.'-%')
            ->pluck('short_code');

        $max = 0;
        foreach ($existing as $code) {
            $n = (int) substr($code, strlen($prefix) + 1);
            $max = max($max, $n);
        }

        return response()->json(['code' => $prefix.'-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT)]);
    }

    public function emailForm(SalesCustomer $customer): View
    {
        return view('sales.customers.email', compact('customer'));
    }

    public function sendEmail(Request $request, SalesCustomer $customer): RedirectResponse
    {
        if (! $customer->email) {
            return back()->withErrors(['email' => 'This customer has no email address on file.']);
        }

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $sender = $request->user();

        try {
            Mail::to($customer->email)->send(
                new CustomerEmail($customer, $sender, $data['subject'], $data['body'])
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['email' => 'The email could not be sent. Please try again later.']);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Email sent to {$customer->company_name} ({$customer->email})."]]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'short_code' => ['nullable', 'string', 'max:40', Rule::unique('sales_customers', 'short_code')->ignore($ignoreId)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('sales_customers', 'email')->ignore($ignoreId)],
            'phone' => ['nullable', 'string', 'max:60'],
            'mobile' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:120'],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_direction' => ['nullable', 'required_with:opening_balance', Rule::in(['debit', 'credit'])],
            'opening_balance_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'opening_balance_direction.required_with' => 'Choose whether this opening balance is owed to you (+) or is a credit held for the customer (-).',
        ]);
    }
}
