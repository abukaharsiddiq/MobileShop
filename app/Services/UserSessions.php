<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Password bodlale ba account inactive korle oi user er onno shob login (onno device/browser)
 * bondho kora. Session database e thake (SESSION_DRIVER=database) tai sekhan theke muchha hoy;
 * "remember me" cookie o notun token diye bekar kora hoy.
 */
class UserSessions
{
    public static function terminate(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
                ->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
