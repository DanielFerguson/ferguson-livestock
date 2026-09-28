<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook event from Stripe, stored before it's processed.
 *
 * @property string $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property int $attempts
 * @property string|null $last_error
 */
#[Fillable(['id', 'type', 'payload', 'received_at', 'processed_at', 'attempts', 'last_error'])]
class StripeEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /**
     * The object the event is about, such as the Checkout Session.
     *
     * @return array<array-key, mixed>
     */
    public function object(): array
    {
        $data = $this->payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        return is_array($object) ? $object : [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
