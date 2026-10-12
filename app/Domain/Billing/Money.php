<?php

namespace App\Domain\Billing;

use InvalidArgumentException;

final class Money
{
    public static function prorate(int $amount, int $remaining, int $period): int
    {
        if ($period <= 0 || $remaining < 0 || $remaining > $period || abs($amount) > 100000000) {
            throw new InvalidArgumentException('Invalid proration inputs.');
        }
        $numerator = abs($amount) * $remaining;
        $rounded = intdiv($numerator + intdiv($period, 2), $period);

        return $amount < 0 ? -$rounded : $rounded;
    }

    public static function format(int $amount, string $currency = 'EUR'): string
    {
        $sign = $amount < 0 ? '−' : '';
        $amount = abs($amount);

        return $sign.($currency === 'EUR' ? '€' : $currency.' ').intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
