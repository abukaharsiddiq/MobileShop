<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;

class RoleMiddleware
{
    // Usage: role:admin  or  role:salesman,manager
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $userRole = Auth::user()->role;

        if (!in_array($userRole, $roles)) {
            if ($request->expectsJson()) {
                abort(403);
            }

            Toastr::error('You do not have permission to access this page.', 'Access Denied');

            // nijer dashboard e pathano (admin -> admin dashboard, baki sobai -> sales dashboard)
            return $userRole == 'admin'
                ? redirect()->route('admin.dashboard')
                : redirect()->route('sales.dashboard');
        }

        return $next($request);
    }
}
