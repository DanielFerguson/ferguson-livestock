<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\ClearsResponseCache;
use Carbon\CarbonImmutable;
use Database\Factories\BundleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product sold as several units of another product: the 10kg beef box is two 5kg boxes, a 5kg mince pack is
 * ten 500g packs. Selling one takes `units` of the component's stock, so the two can never be oversold together.
 *
 * It sits alongside a product's type (box, extra) rather than being one: a bundle keeps the rules of its type.
 *
 * @property int $id
 * @property int $product_id
 * @property int $component_product_id
 * @property int $units
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 * @property-read Product $component
 */
#[Fillable(['product_id', 'component_product_id', 'units'])]
class Bundle extends Model
{
    use ClearsResponseCache;

    /** @use HasFactory<BundleFactory> */
    use HasFactory;

    /**
     * What stops this product from being a bundle of that component, in words the owner can act on. Empty when
     * it can be. The one place these rules live, for the admin, the MCP tools and the seeder.
     *
     * @return list<string>
     */
    public static function problemsFor(Product $product, Product $component, int $units): array
    {
        $problems = [];

        if ($units < 1) {
            $problems[] = 'A bundle uses at least 1 unit of its component.';
        }

        if ($product->type === ProductType::Delivery) {
            $problems[] = 'The delivery fee cannot be a bundle.';
        }

        if ($component->is($product)) {
            $problems[] = 'A product cannot be a bundle of itself.';
        }

        if ($component->type === ProductType::Delivery) {
            $problems[] = 'A bundle cannot be made of the delivery fee.';
        }

        if (self::query()->where('product_id', $component->id)->exists()) {
            $problems[] = "{$component->name} is itself a bundle. A bundle must be made of a product that has its own stock.";
        }

        if (self::query()->where('component_product_id', $product->id)->exists()) {
            $problems[] = "Other bundles are made from {$product->name}, so it cannot be a bundle itself.";
        }

        if (! self::query()->where('product_id', $product->id)->exists() && DropItem::query()->where('product_id', $product->id)->whereNotNull('quantity')->exists()) {
            $problems[] = "{$product->name} is already on a drop with its own stock, so it cannot become a bundle.";
        }

        return $problems;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
        ];
    }
}
