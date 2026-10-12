<?php

namespace App\Jobs;

use App\Application\Records;
use App\Models\DemoSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 15;

    public function __construct(public string $messageId) {}

    public function backoff(): array
    {
        return [5, 20, 60, 120];
    }

    public function handle(): void
    {
        $initial = DB::table('outbox_messages')->where('id', $this->messageId)->first();
        if (! $initial) {
            return;
        }
        DB::transaction(function () use ($initial) {
            $session = DemoSession::whereKey($initial->demo_session_id)->lockForUpdate()->firstOrFail();
            $message = DB::table('outbox_messages')->where('id', $this->messageId)->lockForUpdate()->first();
            if (! $message || $message->status !== 'pending') {
                return;
            }
            if ($session->revoked_at || $session->expires_at->isPast()) {
                DB::table('outbox_messages')->where('id', $this->messageId)->update(['status' => 'canceled']);

                return;
            }
            $data = json_decode($message->payload, true, 16, JSON_THROW_ON_ERROR);
            DB::table('demo_mail')->insertOrIgnore(['outbox_id' => $message->id, 'demo_session_id' => $message->demo_session_id, 'subject' => $data['subject'], 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('outbox_messages')->where('id', $this->messageId)->update(['status' => 'delivered', 'attempts' => $message->attempts + 1, 'updated_at' => now()]);
            Records::audit($message->demo_session_id, $message->id, 'outbox.delivered', 'applied');
        }, 3);
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('outbox_messages')->where('id', $this->messageId)->where('status', 'pending')->update(['status' => 'dead', 'attempts' => $this->tries]);
    }
}
