<?php

namespace App\Domain\Providers;

use App\Models\Order;
use App\Models\Payment;

interface PaymentProvider
{
    public function createCheckout(Order $order): array;

    public function captureOrConfirm(Order $order, string $outcome): array;

    public function refund(Payment $payment, int $amount, string $key): array;

    public function fetchPayment(Order $order): array;

    public function verifyWebhook(string $body, string $signature): bool;

    public function normalizeEvent(array $event): PaymentEvent;

    public function capabilities(): array;
}
