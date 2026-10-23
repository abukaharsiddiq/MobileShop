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

        View::share('extrasetting',ExtraSetting::latest()->first()); 
        View::share('setting',Setting::latest()->first());
    }
}
