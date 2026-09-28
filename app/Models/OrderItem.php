<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order, at the price when it was placed.
 *
 * @property int $id
 * @property int $order_id
 * @property int $drop_item_id
 * @property int $product_id
 * @property int $quantity
 * @property int $unit_price
 * @property int|null $stock_drop_item_id
 * @property int $stock_units
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 * @property-read DropItem $dropItem
 * @property-read Product $product
 */
#[Fillable(['order_id', 'drop_item_id', 'product_id', 'quantity', 'unit_price', 'stock_drop_item_id', 'stock_units'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<DropItem, $this>
     */
    public function dropItem(): BelongsTo
    {
        return $this->belongsTo(DropItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'stock_units' => 'integer',
        ];
    }
}
