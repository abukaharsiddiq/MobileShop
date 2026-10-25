<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Login er por admin kauke inactive korle tar cholman session o shathe shathe bondho hoy
 * (shudhu login er somoy status check korle se logout na kora porjonto kaj chalate parto).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user && !$user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your account is inactive.'], 403);
            }

            return redirect()->route('login')->withErrors(['email' => 'Your account is inactive.']);
        }

        return $next($request);
    }
}
