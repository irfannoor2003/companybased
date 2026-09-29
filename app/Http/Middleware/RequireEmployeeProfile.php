<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts self-service attendance and leave to genuine, attendance-tracked
 * employees.
 *
 * Permission checks alone are not enough: roles such as Admin and Super Admin
 * are back-office accounts with no employee profile, and Accountant may not
 * have one either. Without this guard they would land on a page telling them
 * "No employee profile is linked to your account", or worse, be counted in
 * payroll.
 */
class RequireEmployeeProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAttendanceTracked()) {
            abort(403, 'Attendance is only available to employees on the payroll.');
        }

        return $next($request);
    }
}
