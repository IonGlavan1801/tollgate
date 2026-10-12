<?php

namespace Tests;

use App\Application\Billing;
use App\Application\DemoSessions;
use App\Application\EventProcessor;
use App\Models\DemoSession;
use App\Models\Order;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

abstract class LabTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    protected function demo(): DemoSession
    {
        return app(DemoSessions::class)->create()[0];
    }

    protected function order(DemoSession $demo, string $provider = 'stripe'): Order
    {
        return app(Billing::class)->createOrder($demo->id, DB::table('prices')->orderBy('amount_minor')->value('id'), $provider, (string) Str::uuid());
    }

    protected function drain(Order $order): void
    {
        $events = WebhookEvent::where('order_id', $order->id)->get()->sortBy(fn ($e) => $e->type === 'subscription.created' ? 0 : 1);
        foreach ($events as $event) {
            app(EventProcessor::class)->process($event->id);
        }
    }

    protected function paid(DemoSession $demo): Order
    {
        $order = $this->order($demo);
        app(Billing::class)->checkout($demo->id, $order->id, 'succeeded', (string) Str::uuid());
        $this->drain($order);

        return $order->fresh();
    }
}
