<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $demo_session_id
 * @property string $order_id
 * @property string $provider
 * @property string $environment
 * @property string $external_event_id
 * @property string $type
 * @property string $payload_hash
 * @property array $payload
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property string $correlation_id
 */
class WebhookEvent extends Model
{
    protected $table = 'webhook_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'available_at' => 'immutable_datetime'];
    }
}
