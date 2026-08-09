<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        return redirect()->route($request->user() ? 'dashboard' : 'login');
    }
}
