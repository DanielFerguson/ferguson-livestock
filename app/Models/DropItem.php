<?php

namespace App\Models;

use App\Exceptions\QuantityBelowCommitted;
use App\Models\Concerns\ClearsResponseCache;
use App\Stock\DropSnapshot;
use Carbon\CarbonImmutable;
use Database\Factories\DropItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A product's price, Stripe price and stock in one drop.
 *
 * `available` is what's left to sell after holds and sales. It is only ever changed by relative, conditional
 * updates, never overwritten, so holds taken while the admin is editing stay correct.
 *
 * @property int $id
 * @property int $drop_id
 * @property int $product_id
 * @property int $price
 * @property string $stripe_price_id
 * @property int|null $quantity
 * @property int|null $available
 * @property int $max_per_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Drop $drop
 * @property-read Product $product
 */
#[Fillable(['drop_id', 'product_id', 'price', 'stripe_price_id', 'quantity', 'available', 'max_per_order'])]
class DropItem extends Model
{
    use ClearsResponseCache;

    /** @use HasFactory<DropItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (DropItem $item): void {
            $item->available ??= $item->quantity;
        });
    }

    /**
     * @return BelongsTo<Drop, $this>
     */
    public function drop(): BelongsTo
    {
        return $this->belongsTo(Drop::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function hasOwnStock(): bool
    {
        return $this->quantity !== null;
    }

    /**
     * Units customers are holding in checkout or have bought.
     */
    public function committed(): int
    {
        return (int) $this->quantity - (int) $this->available;
    }

    /**
     * Change the quantity, moving `available` by the same difference in one conditional update.
     *
     * @throws QuantityBelowCommitted when fewer units than customers already hold or bought.
     */
    public function adjustQuantityTo(int $quantity): void
    {
        $updated = DB::transaction(function () use ($quantity): bool {
            $current = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $difference = $quantity - (int) $current->quantity;

            if ($difference === 0) {
                return true;
            }

            return self::query()
                ->whereKey($this->getKey())
                ->where('available', '>=', -$difference)
                ->incrementEach(['quantity' => $difference, 'available' => $difference], ['updated_at' => now()]) === 1;
        });

        DropSnapshot::forget();

        if (! $updated) {
            throw new QuantityBelowCommitted(self::query()->whereKey($this->getKey())->firstOrFail()->committed());
        }

        $this->refresh();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'quantity' => 'integer',
            'available' => 'integer',
            'max_per_order' => 'integer',
        ];
    }
}
