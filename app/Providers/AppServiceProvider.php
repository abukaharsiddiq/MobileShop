<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use View;
use App\Models\Setting;
use App\Models\ExtraSetting;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin sob permission pabe; baki user der shudhu assign kora permission
        Gate::before(function ($user, $ability) {
            return $user->role === 'admin' ? true : null;
        });

        // login/logout/bhul password/lock - audit log e (password kokhono na)
        Event::listen(Login::class, fn (Login $e) => AuditLogger::log('auth.login', $e->user, null, ['remember' => $e->remember]));
        Event::listen(Logout::class, fn (Logout $e) => $e->user ? AuditLogger::log('auth.logout', $e->user) : null);
        Event::listen(Failed::class, fn (Failed $e) => AuditLogger::log('auth.failed', $e->user, null,
            ['email' => mb_substr((string) ($e->credentials['email'] ?? ''), 0, 120)]));
        Event::listen(Lockout::class, fn (Lockout $e) => AuditLogger::log('auth.lockout', null, null,
            ['email' => mb_substr((string) $e->request->input('email'), 0, 120)]));
        Event::listen(PasswordReset::class, fn (PasswordReset $e) => AuditLogger::log('auth.password_reset', $e->user));

        // View render howar somoy load hoy (boot e na), jate migrate/artisan notun database e o chole
        View::composer('*', function ($view) {
            // error page database chara o dekhate hobe (database e somossa holeo)
            if (str_starts_with($view->name(), 'errors.')) {
                return;
            }

            static $settings = null;
            $settings ??= [
                'extrasetting' => ExtraSetting::latest()->first(),
                'setting'      => Setting::latest()->first(),
            ];
            $view->with($settings);
        });
    }
}
