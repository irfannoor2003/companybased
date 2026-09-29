<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(): View
    {
        $user = auth()->user();
        $dashboard = $this->dashboard->forUser($user);

        return view('dashboard', array_merge($dashboard, [
            'isAdmin' => $user->isAdmin(),
            'isSuperAdmin' => $user->isSuperAdmin(),
        ]));
    }
}
