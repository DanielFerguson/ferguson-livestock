<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Exceptions\CannotChangeDrop;
use App\Exceptions\QuantityBelowCommitted;
use App\Models\Bundle;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;
use App\Payments\PaymentGateway;
use App\Payments\PriceCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Adds a product to a drop, or changes its price, Stripe price, stock or order limit.
 *
 * Stock changes move `available` by the same amount, so units customers are holding stay held.
 */
final readonly class SetDropItem
{
    public function __construct(private PaymentGateway $payments) {}

    /**
     * @param  int|null  $priceCents  Left out, the Stripe price's own amount is used.
     * @param  int|null  $quantity  Total units for sale. Only for products with their own stock; 0 stops selling.
     *
     * @throws CannotChangeDrop
     */
    public function __invoke(Drop $drop, Product $product, ?string $stripePriceId = null, ?int $priceCents = null, ?int $quantity = null, ?int $maxPerOrder = null): DropItem
    {
        $product->loadMissing('bundle.component');

        $item = $drop->items()->where('product_id', $product->id)->first();

        $bundle = $product->bundle;

        if ($bundle instanceof Bundle && ! $drop->items()->where('product_id', $bundle->component_product_id)->whereNotNull('quantity')->exists()) {
            throw new CannotChangeDrop("{$product->name} is sold from {$bundle->component->name} stock. Add {$bundle->component->name} (with a quantity) to this drop first.");
        }

        $stripePriceId ??= $item?->stripe_price_id;

        if ($stripePriceId === null) {
            throw new CannotChangeDrop("{$product->name} needs a Stripe price. Use list-stripe-prices to choose one.");
        }

        $priceCents ??= $this->priceFromStripe($stripePriceId) ?? $item?->price;

        if ($priceCents === null) {
            throw new CannotChangeDrop('Give a price in cents; Stripe has no fixed amount for this price.');
        }

        $this->assertPriceMatchesStripe($stripePriceId, $priceCents);

        if ($maxPerOrder !== null && $maxPerOrder < 1) {
            throw new CannotChangeDrop('The limit per order must be at least 1.');
        }

        if ($quantity !== null && $quantity < 0) {
            throw new CannotChangeDrop('Stock cannot be negative.');
        }

        if ($product->hasOwnStock() && $quantity === null && $item?->hasOwnStock() !== true) {
            throw new CannotChangeDrop("{$product->name} needs a stock quantity.");
        }

        if (! $product->hasOwnStock() && $quantity !== null) {
            throw new CannotChangeDrop($bundle instanceof Bundle
                ? "{$product->name} is sold from {$bundle->component->name} stock ({$bundle->units} each), so it has no quantity of its own. Set {$bundle->component->name}'s quantity instead."
                : "{$product->name} is a fee with no stock, so it has no quantity to set.");
        }

        return DB::transaction(function () use ($drop, $product, $item, $stripePriceId, $priceCents, $quantity, $maxPerOrder): DropItem {
            if (! $item instanceof DropItem) {
                return $drop->items()->create([
                    'product_id' => $product->id,
                    'price' => $priceCents,
                    'stripe_price_id' => $stripePriceId,
                    'quantity' => $quantity,
                    'available' => $quantity,
                    'max_per_order' => $maxPerOrder ?? 10,
                ])->load('product.bundle.component');
            }

            $item->update(array_filter([
                'price' => $priceCents,
                'stripe_price_id' => $stripePriceId,
                'max_per_order' => $maxPerOrder,
            ], fn (mixed $value): bool => $value !== null));

            if ($quantity !== null) {
                try {
                    $item->adjustQuantityTo($quantity);
                } catch (QuantityBelowCommitted $exception) {
                    throw new CannotChangeDrop("{$product->name}: {$exception->getMessage()}", $exception->getCode(), previous: $exception);
                }
            }

            return $item->refresh()->load('product.bundle.component');
        });
    }

    private function priceFromStripe(string $stripePriceId): ?int
    {
        try {
            return $this->payments->retrievePrice($stripePriceId)?->unitAmount;
        } catch (Throwable) {
            return null;
        }
    }

    private function assertPriceMatchesStripe(string $stripePriceId, int $priceCents): void
    {
        try {
            $price = $this->payments->retrievePrice($stripePriceId);
        } catch (Throwable) {
            throw new CannotChangeDrop('Could not reach Stripe to check this price. Try again in a minute.');
        }

        $problems = PriceCheck::problems($price, $priceCents);

        if ($problems !== []) {
            throw new CannotChangeDrop(implode(' ', $problems));
        }
    }
}
