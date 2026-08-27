<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DiscountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiscountRuleController extends Controller
{
    public function index(): View
    {
        $rules = DiscountRule::orderBy('name')->get();

        return view('settings.discount-rules.index', compact('rules'));
    }

    public function create(): View
    {
        return view('settings.discount-rules.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'max_value' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'roles' => ['required', 'array', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        DiscountRule::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'max_value' => $data['max_value'],
            'currency' => $data['currency'] ?? null,
            'roles' => $data['roles'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('settings.discount-rules.index')
            ->with('toasts', [['type' => 'success', 'message' => "Discount rule \"{$data['name']}\" created."]]);
    }

    public function edit(DiscountRule $discountRule): View
    {
        return view('settings.discount-rules.edit', ['rule' => $discountRule]);
    }

    public function update(Request $request, DiscountRule $discountRule): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'max_value' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'roles' => ['required', 'array', 'min:1'],
            'is_active' => ['boolean'],
        ]);

        $discountRule->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'max_value' => $data['max_value'],
            'currency' => $data['currency'] ?? null,
            'roles' => $data['roles'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Discount rule \"{$data['name']}\" updated."]]);
    }

    public function destroy(DiscountRule $discountRule): RedirectResponse
    {
        $discountRule->delete();

        return redirect()->route('settings.discount-rules.index')
            ->with('toasts', [['type' => 'success', 'message' => "Discount rule deleted."]]);
    }
}
