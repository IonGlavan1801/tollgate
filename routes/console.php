<?php

use App\Jobs\DeliverOutbox;
use App\Jobs\ProcessWebhook;
use App\Models\DemoSession;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('lab:recover', function () {
    WebhookEvent::whereIn('status', ['persisted', 'deferred'])->where('available_at', '<=', now())->orderBy('created_at')->limit(200)->get()->each(fn ($event) => ProcessWebhook::dispatch($event->id));
    DB::table('outbox_messages')->where('status', 'pending')->where('available_at', '<=', now())->limit(200)->get()->each(fn ($message) => DeliverOutbox::dispatch($message->id));
    $this->info('Pending inbox and outbox jobs dispatched.');
})->purpose('Recover durable inbox events and deliver demo mail');

Artisan::command('lab:expire', function () {
    $count = DemoSession::where('environment', 'simulated')->whereNull('revoked_at')->where('expires_at', '<=', now())->update(['revoked_at' => now()]);
    $expired = DemoSession::where('environment', 'simulated')->where('expires_at', '<', now()->subDay())->pluck('id');
    DB::table('webhook_events')->whereIn('demo_session_id', $expired)->whereNotIn('status', ['applied', 'ignored_stale', 'rejected', 'dead'])->update(['status' => 'rejected']);
    DB::table('webhook_events')->whereIn('demo_session_id', $expired)->update(['payload' => '{}']);
    DB::table('demo_mail')->whereIn('demo_session_id', $expired)->delete();
    DB::table('outbox_messages')->whereIn('demo_session_id', $expired)->update(['payload' => '{}', 'status' => 'expired']);
    $this->info("Expired {$count} sessions; compacted old payloads. Synthetic ledger records remain append-only.");
})->purpose('Expire demo access and compact temporary payloads');

Schedule::command('lab:recover')->everyMinute()->withoutOverlapping();
Schedule::command('lab:expire')->hourly()->withoutOverlapping();

Schedule::command('sandbox:maintain --limit=20')->everyMinute()->withoutOverlapping(10);
