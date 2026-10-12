<?php

use App\Http\Controllers\LabApiController;
use App\Http\Controllers\WebhookController;
use App\Livewire\Lab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', Lab::class)->name('lab');
Route::post('/api/demo/sessions', [LabApiController::class, 'createSession'])->middleware('throttle:demo-create');
Route::middleware(['demo', 'throttle:lab-api'])->group(function () {
    Route::post('/api/orders', [LabApiController::class, 'order']);
    Route::post('/api/orders/{id}/checkout', [LabApiController::class, 'checkout']);
    Route::post('/api/subscriptions/{id}/preview-change', [LabApiController::class, 'preview']);
    Route::post('/api/subscriptions/{id}/change', [LabApiController::class, 'change']);
    Route::post('/api/payments/{id}/refunds', [LabApiController::class, 'refund']);
    Route::post('/api/demo/scenarios/{scenario}', [LabApiController::class, 'scenario']);
    Route::get('/api/demo/sessions/{id}/events', [LabApiController::class, 'events']);
});
Route::post('/webhooks/{provider}', WebhookController::class)->middleware('throttle:webhook');

Route::get('/health', function () {
    try {
        DB::select('SELECT 1');
        Redis::connection()->ping();

        return response()->json(['status' => 'ok']);
    } catch (Throwable) {
        return response()->json(['status' => 'unavailable'], 503);
    }
})->middleware('throttle:30,1');
