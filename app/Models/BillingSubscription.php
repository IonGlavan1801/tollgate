<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $demo_session_id
 * @property int $price_id
 * @property string $provider
 * @property string $environment
 * @property string $status
 * @property int $version
 * @property bool $cancel_at_period_end
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 */
class BillingSubscription extends Model
{
    protected $table = 'subscriptions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'cancel_at_period_end' => 'boolean', 'period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime'];
    }
}
