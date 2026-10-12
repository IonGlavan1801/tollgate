<?php

namespace App\Domain\Billing;

use DomainException;

enum SubscriptionState: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Paused = 'paused';
    case Canceled = 'canceled';

    public function transition(self $next): self
    {
        if ($next === $this) {
            return $this;
        }
        $allowed = match ($this) {
            self::Trialing => [self::Active, self::PastDue, self::Canceled],
            self::Active => [self::PastDue, self::Paused, self::Canceled],
            self::PastDue => [self::Active, self::Suspended, self::Canceled],
            self::Suspended, self::Paused => [self::Active, self::Canceled],
            self::Canceled => [],
        };
        if (! in_array($next, $allowed, true)) {
            throw new DomainException('This subscription transition is not supported.');
        }

        return $next;
    }
}
