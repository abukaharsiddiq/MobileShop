<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use View;
use App\Models\Setting;
use App\Models\ExtraSetting;

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
