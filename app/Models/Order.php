<?php

namespace App\Models;

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
        return ['amount_minor' => 'integer', 'version' => 'integer'];
    }
}
