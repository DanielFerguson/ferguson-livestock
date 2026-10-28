<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Exceptions\CannotChangeDrop;
use App\Exceptions\QuantityBelowCommitted;
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
        $item = $drop->items()->where('product_id', $product->id)->first();

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
            throw new CannotChangeDrop("{$product->name} has no stock of its own (it is sold from another product's stock or is a fee), so it has no quantity to set.");
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
                ])->load('product');
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

            return $item->refresh()->load('product');
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
