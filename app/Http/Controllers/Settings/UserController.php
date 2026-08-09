<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->with(['roles'])
            ->when($request->filled('search'), fn ($q) => $q
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('first_name', 'like', "%{$request->search}%")
                    ->orWhere('last_name', 'like', "%{$request->search}%")))
            ->when($request->filled('role'), fn ($q) => $q->role($request->role))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $roles = Role::orderBy('name')->get();

        return view('settings.users.index', compact('users', 'roles'));
    }

    public function create(): View
    {
        $roles = Role::orderBy('name')->get();

        return view('settings.users.create', compact('roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:60'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
            'is_active' => ['boolean'],
        ]);

        $user = User::create([
            'name' => trim("{$data['first_name']} {$data['last_name']}"),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $user->syncRoles(array_map('intval', $data['roles']));

        return redirect()->route('settings.users.index')
            ->with('toasts', [['type' => 'success', 'message' => "User {$user->name} created."]]);
    }

    public function edit(User $user): View
    {
        $roles = Role::orderBy('name')->get();

        return view('settings.users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:60'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
            'is_active' => ['boolean'],
            'access_until' => ['nullable', 'date', 'after:now'],
        ]);

        $user->fill([
            'name' => trim("{$data['first_name']} {$data['last_name']}"),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'is_active' => $request->boolean('is_active', true),
            'access_until' => $data['access_until'] ?? null,
        ]);

        if ($request->filled('password')) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();
        $user->syncRoles(array_map('intval', $data['roles']));

        return back()->with('toasts', [['type' => 'success', 'message' => "User {$user->name} updated."]]);
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->is(auth()->user())) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'You cannot delete your own account.']]);
        }

        if ($user->isSuperAdmin()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'The Super Admin account cannot be deleted.']]);
        }

        $name = $user->name;
        $user->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "User {$name} deactivated."]]);
    }
}
