<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Observers\AutomationSourceObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        foreach ([Customer::class, Lead::class, Appointment::class] as $model) {
            $model::observe(AutomationSourceObserver::class);
        }
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('password-recovery', fn (Request $request) => [
            Limit::perMinute(5)->by('recovery-ip:'.$request->ip()),
            Limit::perMinute(3)->by('recovery-email:'.hash('sha256', strtolower((string) $request->input('email')))),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('reset:'.$request->ip()));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('change:'.$request->user()?->id));
        RateLimiter::for('password-admin', fn (Request $request) => [
            Limit::perMinute(10)->by('admin-reset:'.$request->user()?->id),
            Limit::perMinute(3)->by('admin-reset-target:'.$request->route('user')),
        ]);
    }
}
