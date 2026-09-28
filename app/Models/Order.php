<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's order in a drop. Its stock is held from the moment it's created until it's paid, or released
 * exactly once if it expires or its payment fails.
 *
 * @property int $id
 * @property string $public_id
 * @property int $drop_id
 * @property OrderStatus $status
 * @property DeliveryMethod $delivery_method
 * @property CarbonImmutable|null $delivery_day
 * @property string|null $customer_name
 * @property string|null $email
 * @property string|null $phone
 * @property array{line1: string|null, line2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string|null}|null $shipping_address
 * @property int $total
 * @property int $amount_refunded
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string $session_fingerprint
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $fulfilled_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Drop $drop
 * @property-read Collection<int, OrderItem> $items
 */
#[Fillable(['drop_id', 'status', 'delivery_method', 'delivery_day', 'customer_name', 'email', 'phone', 'shipping_address', 'total', 'amount_refunded', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'session_fingerprint', 'expires_at', 'paid_at', 'released_at', 'fulfilled_at', 'refunded_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Only the public ID is a ULID; the primary key stays an auto-incrementing integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<Drop, $this>
     */
    public function drop(): BelongsTo
    {
        return $this->belongsTo(Drop::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The order number customers see, e.g. FL-7K2QXM.
     */
    public function reference(): string
    {
        return 'FL-'.strtoupper(substr($this->public_id, -6));
    }

    public function firstName(): ?string
    {
        return $this->customer_name === null ? null : explode(' ', trim($this->customer_name))[0];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_method' => DeliveryMethod::class,
            'delivery_day' => 'immutable_date',
            'shipping_address' => 'array',
            'total' => 'integer',
            'amount_refunded' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }
}
