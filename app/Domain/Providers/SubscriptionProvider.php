<?php

namespace App\Domain\Providers;

use App\Models\BillingSubscription;
use App\Models\Order;

interface SubscriptionProvider
{
    public function create(Order $order): array;

    public function changePlan(BillingSubscription $subscription, int $priceId): array;

    public function pause(BillingSubscription $subscription): array;

    public function cancel(BillingSubscription $subscription, bool $atPeriodEnd): array;

    public function fetch(BillingSubscription $subscription): array;
}
