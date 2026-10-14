<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $demo_session_id
 * @property int $price_id
 * @property string $provider
 * @property string $environment
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property int $version
 * @property int $snapshot_version
 * @property CarbonImmutable|null $reconciled_at
 * @property CarbonImmutable|null $reconciliation_attempted_at
 * @property CarbonImmutable|null $cleanup_completed_at
 * @property string $correlation_id
 */
class Order extends Model
{
    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'version' => 'integer', 'snapshot_version' => 'integer', 'reconciled_at' => 'immutable_datetime', 'reconciliation_attempted_at' => 'immutable_datetime', 'cleanup_completed_at' => 'immutable_datetime'];
    }
}
