<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $provider
 * @property string $environment
 * @property string $external_id
 * @property int $amount_minor
 * @property int $refunded_minor
 * @property string $currency
 * @property string $status
 */
class Payment extends Model
{
    protected $table = 'payments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'refunded_minor' => 'integer'];
    }
}
