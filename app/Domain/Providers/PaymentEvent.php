<?php

namespace App\Domain\Providers;

final readonly class PaymentEvent
{
    public function __construct(
        public string $externalId,
        public string $orderId,
        public string $type,
        public int $amountMinor,
        public string $currency,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->externalId, 'order_id' => $this->orderId, 'type' => $this->type, 'amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
