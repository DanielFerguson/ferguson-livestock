<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A customer's order in a drop. Its stock is held from the moment it's created until it's paid, or released
 * exactly once if it expires, its payment fails or (for a cash order) it's cancelled.
 *
 * @property int $id
 * @property string $public_id
 * @property string $access_token
 * @property int $drop_id
 * @property OrderStatus $status
 * @property PaymentMethod $payment_method
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
 * @property string|null $session_fingerprint
 * @property bool $via_early_access
 * @property array{ip: string|null, user_agent: string|null, fbp: string|null, fbc: string|null}|null $meta_visitor
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property int|null $confirmation_sms_message_id
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $fulfilled_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Drop $drop
 * @property-read SmsMessage|null $confirmationText
 * @property-read Collection<int, OrderItem> $items
 * @property-read OrderFeedback|null $feedback
 */
#[Fillable(['drop_id', 'status', 'payment_method', 'delivery_method', 'delivery_day', 'customer_name', 'email', 'phone', 'shipping_address', 'total', 'amount_refunded', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'session_fingerprint', 'via_early_access', 'meta_visitor', 'utm_source', 'utm_medium', 'utm_campaign', 'expires_at', 'paid_at', 'confirmation_sms_message_id', 'released_at', 'fulfilled_at', 'refunded_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Mirrors the column's default, so an order checkout has just created knows it's paid through Stripe.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payment_method' => 'stripe',
    ];

    /**
     * How long the secret in an order page's link is: about 95 random bits, hopeless to guess.
     */
    public const int ACCESS_TOKEN_LENGTH = 16;

    /**
     * What that secret looks like, as a regular expression without delimiters. The order page's routes and the short
     * domain's links only take a token of this shape, so anything else is turned away without a lookup.
     */
    public const string ACCESS_TOKEN_PATTERN = '[A-Za-z0-9]{'.self::ACCESS_TOKEN_LENGTH.'}';

    /**
     * Every order gets the secret for its page link as it's created.
     */
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->access_token ??= Str::random(self::ACCESS_TOKEN_LENGTH);
        });
    }

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
     * The text telling the customer about this order, waiting or sent. One text can cover several of their orders.
     *
     * @return BelongsTo<SmsMessage, $this>
     */
    public function confirmationText(): BelongsTo
    {
        return $this->belongsTo(SmsMessage::class, 'confirmation_sms_message_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * What the customer thought of it, left on its page.
     *
     * @return HasOne<OrderFeedback, $this>
     */
    public function feedback(): HasOne
    {
        return $this->hasOne(OrderFeedback::class);
    }

    /**
     * The order number customers see, e.g. FL-7K2QXM.
     */
    public function reference(): string
    {
        return 'FL-'.strtoupper(substr($this->public_id, -6));
    }

    /**
     * The customer's own page for this order. The secret in it is the only key, so it only ever goes to the customer.
     */
    public function pageUrl(): string
    {
        return route('orders.show', ['customerOrder' => $this->access_token]);
    }

    /**
     * The page's link as order texts give it: "https://fergs.au/7Kp2Qx9mZr4TbW3n" on the short domain, or the page's
     * full address when there isn't one. The scheme stays in: phones open a link without one over plain http, which
     * would send the secret in the clear before the redirect to https.
     */
    public function shortLink(): string
    {
        $shortDomain = config('shop.short_domain');

        return is_string($shortDomain) && $shortDomain !== ''
            ? "https://{$shortDomain}/{$this->access_token}"
            : $this->pageUrl();
    }

    /**
     * The night a delivery goes out, as customers read it: "Saturday night, 14 November", or "Saturday or Sunday
     * night" when they said either night suits. Null for a pickup.
     */
    public function deliveryNight(): ?string
    {
        if ($this->delivery_method !== DeliveryMethod::Delivery) {
            return null;
        }

        if ($this->delivery_day instanceof CarbonImmutable) {
            return $this->delivery_day->format('l').' night, '.$this->delivery_day->format('j F');
        }

        return ($this->loadMissing('drop')->drop->deliveryWeekdays() ?? 'Either').' night';
    }

    /**
     * The delivery night as the farm reads it on its lists: "Sat 14 Nov", or "Either night" when the customer said
     * either suits. Null for a pickup.
     */
    public function deliveryNightShort(): ?string
    {
        if ($this->delivery_method !== DeliveryMethod::Delivery) {
            return null;
        }

        return $this->delivery_day?->format('D j M') ?? 'Either night';
    }

    /**
     * How the order goes out, as the farm's lists put it: "Farm pickup", or "Delivery, Sat 14 Nov".
     */
    public function deliverySummary(): string
    {
        $night = $this->deliveryNightShort();

        return $night === null ? 'Farm pickup' : "Delivery, {$night}";
    }

    /**
     * The day it was handed over, as the farm's lists put it: "Sat 14 Nov". Null until it's fulfilled.
     */
    public function fulfilledDay(): ?string
    {
        return $this->fulfilled_at?->setTimezone(config()->string('shop.timezone'))->format('D j M');
    }

    /**
     * Whether all of it has been given back. A refund leaves the order's status as it was, so this goes by the amount.
     */
    public function isRefundedInFull(): bool
    {
        return $this->total > 0 && $this->amount_refunded >= $this->total;
    }

    public function firstName(): ?string
    {
        return $this->customer_name === null ? null : explode(' ', trim($this->customer_name))[0];
    }

    /**
     * The email address with most of its name hidden, for the order page: "s•••@gmail.com". Null without one.
     */
    public function emailHint(): ?string
    {
        if ($this->email === null || ! str_contains($this->email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $this->email, 2);

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'delivery_method' => DeliveryMethod::class,
            'delivery_day' => 'immutable_date',
            'shipping_address' => 'array',
            'total' => 'integer',
            'amount_refunded' => 'integer',
            'via_early_access' => 'boolean',
            'meta_visitor' => 'array',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }
}
