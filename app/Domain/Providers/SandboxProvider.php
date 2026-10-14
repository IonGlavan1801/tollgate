<?php

namespace App\Domain\Providers;

use App\Models\Order;

interface SandboxProvider
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array{id?: string, url?: string, status?: string} Sanitized result, never credentials or customer PII.
     */
    public function execute(Order $order, string $action, array $parameters, string $key): array;

    /**
     * @return array{
     *     order_status?: string,
     *     mandate_ready?: bool,
     *     subscription?: array{id: string, price_id: int, status: string, period_start: int, period_end: int, cancel_at_period_end: bool},
     *     payments?: list<array{id: string, amount_minor: int, currency: string, initial: bool, refunds: list<array{id: string, amount_minor: int, status: string}>, dispute: array{id: string, amount_minor: int, status: string}|null}>
     * } Authoritative current state, fetched from fixed provider API hosts.
     */
    public function snapshot(Order $order): array;

    public function syncPrice(int $priceId, string $key): string;
}
