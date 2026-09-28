<?php

namespace App\Actions;

use App\Enums\ProductType;
use App\Models\Drop;
use App\Models\DropItem;
use App\Payments\PaymentGateway;
use App\Payments\PriceCheck;
use App\Payments\StripeWebhookEvents;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Checks everything a drop needs before it opens, and records the result on the drop.
 */
final readonly class RunDropPreflight
{
    public function __construct(private PaymentGateway $payments) {}

    /**
     * @return array{passed: bool, problems: list<string>, checked_at: string}
     */
    public function __invoke(Drop $drop): array
    {
        $drop->loadMissing('items.product');

        $problems = [...$this->stockProblems($drop), ...$this->deliveryProblems($drop)];

        try {
            $problems = [...$problems, ...$this->priceProblems($drop), ...$this->webhookProblems()];
        } catch (Throwable $exception) {
            $problems[] = 'Couldn’t reach Stripe to check this drop: '.$exception->getMessage();
        }

        $report = ['passed' => $problems === [], 'problems' => $problems, 'checked_at' => now()->toIso8601String()];

        $drop->update(['preflight_ran_at' => now(), 'preflight_report' => $report]);

        return $report;
    }

    /**
     * @return list<string>
     */
    private function stockProblems(Drop $drop): array
    {
        if ($drop->items->isEmpty()) {
            return ['Add at least one product to this drop.'];
        }

        $problems = [];
        $hasOpened = now()->gte($drop->opens_at);

        foreach ($drop->items->filter(fn (DropItem $item) => $item->hasOwnStock()) as $item) {
            if ($item->quantity === 0) {
                $problems[] = "{$item->product->name} has no stock. Set a quantity.";
            } elseif (! $hasOpened && $item->committed() > 0) {
                $problems[] = "{$item->product->name} has {$item->committed()} units held or sold before the drop has opened.";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function deliveryProblems(Drop $drop): array
    {
        $problems = [];

        if (! $drop->items->contains(fn (DropItem $item) => $item->product->type === ProductType::Delivery)) {
            $problems[] = 'Add the delivery fee to this drop.';
        }

        if ($drop->delivery_days === []) {
            return [...$problems, 'Add at least one delivery day.'];
        }

        $openingDay = $drop->opens_at->setTimezone(config()->string('shop.timezone'))->toDateString();

        foreach ($drop->delivery_days as $day) {
            if ($day < $openingDay) {
                $problems[] = 'Delivery day '.CarbonImmutable::parse($day)->format('D j M').' is before the drop opens.';
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function priceProblems(Drop $drop): array
    {
        $problems = [];

        foreach ($drop->items as $item) {
            foreach (PriceCheck::problems($this->payments->retrievePrice($item->stripe_price_id), $item->price) as $problem) {
                $problems[] = "{$item->product->name}: {$problem}";
            }
        }

        return $problems;
    }

    /**
     * Locally, `stripe listen` forwards events without a registered endpoint, so there's nothing to check.
     *
     * @return list<string>
     */
    private function webhookProblems(): array
    {
        if (app()->isLocal()) {
            return [];
        }

        return StripeWebhookEvents::problems($this->payments->webhookEndpoints(), config()->string('app.url'));
    }
}
