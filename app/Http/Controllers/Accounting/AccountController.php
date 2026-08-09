<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $accounts = Account::query()
            ->with('children')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q
                ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        $totals = collect(Account::typeOptions())->mapWithKeys(function (string $type) {
            $sum = Account::query()->where('type', $type)->get()->sum(fn (Account $a) => $a->balance());
            return [$type => round($sum, 2)];
        });

        return view('accounting.accounts.index', compact('accounts', 'totals'));
    }

    public function create(): View
    {
        return view('accounting.accounts.create', [
            'parents' => Account::query()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $account = Account::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'sub_type' => $data['sub_type'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'currency' => $data['currency'],
            'is_active' => $request->boolean('is_active', true),
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('accounting.accounts.show', $account)
            ->with('toasts', [['type' => 'success', 'message' => "Account \"{$account->code} {$account->name}\" created."]]);
    }

    public function show(Account $account): View
    {
        $account->load([
            'children' => fn ($q) => $q->orderBy('code'),
            'journalItems' => fn ($q) => $q->whereHas('entry', fn ($e) => $e->where('status', 'posted'))->with('entry')->latest(),
        ]);

        return view('accounting.accounts.show', compact('account'));
    }

    public function edit(Account $account): View
    {
        return view('accounting.accounts.edit', [
            'account' => $account,
            'parents' => Account::query()->whereKeyNot($account->id)->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validateData($request, $account->id);

        $account->update([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'sub_type' => $data['sub_type'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'currency' => $data['currency'],
            'is_active' => $request->boolean('is_active', true),
            'description' => $data['description'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Account \"{$account->code} {$account->name}\" updated."]]);
    }

    public function destroy(Account $account): RedirectResponse
    {
        if ($account->children()->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This account has child accounts and cannot be deleted.']]);
        }

        if ($account->journalItems()->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This account has journal activity and cannot be deleted.']]);
        }

        $label = "{$account->code} {$account->name}";
        $account->delete();

        return redirect()->route('accounting.accounts.index')
            ->with('toasts', [['type' => 'success', 'message' => "Account \"{$label}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $accounts = Account::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->orderBy('code')
            ->get();

        return $this->streamCsv('chart-of-accounts-'.now()->format('Y-m-d').'.csv', ['Code', 'Name', 'Type', 'Sub type', 'Parent', 'Currency', 'Balance', 'Status'], $accounts->map(fn (Account $a) => [
            $a->code,
            $a->name,
            Account::typeLabel($a->type),
            $a->sub_type,
            $a->parent?->code,
            $a->currency,
            $a->balance(),
            $a->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Account::typeOptions())],
            'sub_type' => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'currency' => ['required', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! empty($data['parent_id'])) {
            $parent = Account::find($data['parent_id']);
            if ($parent && $parent->type !== $data['type']) {
                $data['type'] = $parent->type;
            }
        }

        return $data;
    }
}