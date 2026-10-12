<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('demo-create', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        RateLimiter::for('lab-api', fn (Request $request) => [Limit::perMinute(120)->by('ip:'.$request->ip()), Limit::perMinute(60)->by('session:'.$request->attributes->get('demo_session_id'))]);
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('livewire', fn (Request $request) => Limit::perMinute(90)->by($request->ip()));
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)->middleware(['web', 'throttle:livewire']));
    }
}
