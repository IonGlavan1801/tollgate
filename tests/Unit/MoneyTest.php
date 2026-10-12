<?php

namespace Tests\Unit;

use App\Domain\Billing\Money;
use App\Domain\Billing\SubscriptionState;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_proration_rounds_symmetrically_without_floats(): void
    {
        $this->assertSame(1, Money::prorate(1, 1, 2));
        $this->assertSame(-1, Money::prorate(-1, 1, 2));
        $this->assertSame(4000, Money::prorate(8000, 15, 30));
        $this->assertSame('€19.00', Money::format(1900));
    }

    public function test_canceled_subscription_cannot_reactivate(): void
    {
        $this->expectException(\DomainException::class);
        SubscriptionState::Canceled->transition(SubscriptionState::Active);
    }
}
