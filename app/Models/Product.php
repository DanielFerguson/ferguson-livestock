<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\ClearsResponseCache;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something that can be sold in a drop. Prices and stock live on each drop's items.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property ProductType $type
 * @property int|null $stock_product_id
 * @property int $stock_units
 * @property array{weight_kg: int, contents: list<string>, best_for: string, freezer_guidance: string}|null $box_details
 * @property int $sort
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product|null $stockProduct
 */
#[Fillable(['slug', 'name', 'description', 'type', 'stock_product_id', 'stock_units', 'box_details', 'sort'])]
class Product extends Model
{
    use ClearsResponseCache;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The product whose stock this one draws on, e.g. the 5kg box for the 10kg box.
     *
     * @return BelongsTo<Product, $this>
     */
    public function stockProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_product_id');
    }

    /**
     * @return HasMany<DropItem, $this>
     */
    public function dropItems(): HasMany
    {
        return $this->hasMany(DropItem::class);
    }

    /**
     * Whether a drop tracks a quantity for this product. Delivery and products that borrow stock don't.
     */
    public function hasOwnStock(): bool
    {
        return $this->type !== ProductType::Delivery && $this->stock_product_id === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'box_details' => 'array',
            'stock_units' => 'integer',
            'sort' => 'integer',
        ];
    }
}
