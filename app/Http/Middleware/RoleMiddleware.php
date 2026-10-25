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

        $user = Auth::user();

        if (!in_array($user->role, $roles, true)) {
            if ($request->expectsJson()) {
                abort(403);
            }

            // nijer dashboard e pathano; kintu dashboard tai jodi ei page hoy (ba nei) tahole
            // redirect loop na kore 403
            $home = $user->homeRoute();
            if ($request->routeIs($home) || !app('router')->has($home)) {
                abort(403);
            }

            Toastr::error('You do not have permission to access this page.', 'Access Denied');

            return redirect()->route($home);
        }

        return $next($request);
    }
}
