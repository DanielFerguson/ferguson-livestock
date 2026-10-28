<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\ClearsResponseCache;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something that can be sold in a drop. Prices and stock live on each drop's items.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property ProductType $type
 * @property array{weight_kg: int, contents: list<string>, best_for: string, freezer_guidance: string}|null $box_details
 * @property int $sort
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Bundle|null $bundle
 */
#[Fillable(['slug', 'name', 'description', 'type', 'box_details', 'sort'])]
class Product extends Model
{
    use ClearsResponseCache;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * What this product is a bundle of, if it is one: the 10kg box is two 5kg boxes.
     *
     * @return HasOne<Bundle, $this>
     */
    public function bundle(): HasOne
    {
        return $this->hasOne(Bundle::class);
    }

    /**
     * @return HasMany<DropItem, $this>
     */
    public function dropItems(): HasMany
    {
        return $this->hasMany(DropItem::class);
    }

    public function isBundle(): bool
    {
        return $this->bundle !== null;
    }

    /**
     * Whether a drop tracks a quantity for this product. Delivery and bundles don't: a bundle uses its
     * component's stock.
     */
    public function hasOwnStock(): bool
    {
        return $this->type !== ProductType::Delivery && ! $this->isBundle();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'box_details' => 'array',
            'sort' => 'integer',
        ];
    }
}
