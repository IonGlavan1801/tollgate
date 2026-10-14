<?php

namespace App\Jobs;

use App\Application\EventProcessor;
use App\Application\Records;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public function __construct(public string $eventId, public bool $crashAfterCommit = false) {}

    public function backoff(): array
    {
        return [3, 10, 30, 60, 120];
    }

    public function handle(EventProcessor $processor): void
    {
        $processor->process($this->eventId);
        if ($this->crashAfterCommit) {
            $event = WebhookEvent::findOrFail($this->eventId);
            Records::audit($event->demo_session_id, $event->correlation_id, $this->attempts() === 1 ? 'worker.interrupted' : 'worker.recovered', $this->attempts() === 1 ? 'deferred' : 'applied', ['queue_attempt' => $this->attempts(), 'after_commit' => true]);
        }
        if ($this->crashAfterCommit && $this->attempts() === 1) {
            throw new \RuntimeException('Simulated worker interruption after commit.');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        WebhookEvent::whereKey($this->eventId)->whereNotIn('status', ['applied', 'ignored_stale', 'rejected'])->update(['status' => 'dead']);
    }
}
