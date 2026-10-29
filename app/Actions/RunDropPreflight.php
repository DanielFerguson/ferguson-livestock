<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProductType;
use App\Models\Bundle;
use App\Models\Drop;
use App\Models\DropItem;
use App\Payments\PaymentGateway;
use App\Payments\PriceCheck;
use App\Payments\StripeWebhookEvents;
use App\Support\Turnstile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $drop->loadMissing('items.product.bundle');

        $problems = [...$this->stockProblems($drop), ...$this->deliveryProblems($drop), ...$this->botCheckProblems(), ...$this->infrastructureProblems()];

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
        // Units sold or held through the early-access link before opening are expected, not a leftover.
        $hasEarlyOrders = $drop->orders()->where('via_early_access', true)->exists();

        foreach ($drop->items as $item) {
            $bundle = $item->product->bundle;

            if ($bundle instanceof Bundle && $drop->items->doesntContain(fn (DropItem $other): bool => $other->product_id === $bundle->component_product_id && $other->hasOwnStock())) {
                $problems[] = "{$item->product->name} is sold from {$bundle->component->name} stock, but {$bundle->component->name} isn't on this drop with a quantity.";
            }
        }

        foreach ($drop->items->filter(fn (DropItem $item): bool => $item->hasOwnStock()) as $item) {
            if ($item->quantity === 0) {
                $problems[] = "{$item->product->name} has no stock. Set a quantity.";
            } elseif (! $hasOpened && ! $hasEarlyOrders && $item->committed() > 0) {
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

        if ($drop->items->doesntContain(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery)) {
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
     * Without both Turnstile keys the bot check quietly lets everyone through, which is only right while developing.
     *
     * @return list<string>
     */
    private function botCheckProblems(): array
    {
        if (app()->isLocal() || Turnstile::isEnabled()) {
            return [];
        }

        return ['Cloudflare’s bot check is off, so the order and wait-list forms accept anything. Set TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY in this environment.'];
    }

    /**
     * What a drop quietly depends on once it opens: email, texts, a queue worker, the live Stripe key and the cache.
     * Each of these fails without anyone seeing an error, so they're checked here rather than discovered on the day.
     *
     * @return list<string>
     */
    private function infrastructureProblems(): array
    {
        if (app()->isLocal()) {
            return [];
        }

        $problems = [];

        if (in_array(config('mail.default'), ['log', 'array', 'null'], true) || (config('mail.default') === 'resend' && blank(config('services.resend.key')))) {
            $problems[] = 'Order emails won’t reach customers: set MAIL_MAILER=resend and RESEND_API_KEY in this environment.';
        }

        if (blank(config('services.twilio.sid')) || blank(config('services.twilio.token')) || blank(config('services.twilio.from'))) {
            $problems[] = 'Texts aren’t set up, so the wait list can’t be told the drop is open: set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM_NUMBER.';
        }

        $problems = [...$problems, ...$this->queueProblems(), ...$this->cacheProblems(), ...$this->settingsProblems()];

        if (Turnstile::isEnabled() && Turnstile::secretIsRejected()) {
            $problems[] = 'Cloudflare doesn’t accept the bot-check secret, so every customer would be turned away. Check TURNSTILE_SECRET_KEY.';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function queueProblems(): array
    {
        if (config('queue.default') === 'sync') {
            return ['There’s no queue worker: jobs run inside the customer’s request, so a slow email slows their order. Use the managed queue.'];
        }

        $problems = [];

        try {
            Queue::size();
        } catch (Throwable $exception) {
            $problems[] = 'Can’t reach the queue ('.$exception->getMessage().'). Order emails and stock releases depend on it.';
        }

        $failed = DB::table('failed_jobs')->count();

        if ($failed > 0) {
            $problems[] = "{$failed} background ".($failed === 1 ? 'job has' : 'jobs have').' failed. Look at them before the drop: they may be order emails or payments.';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function cacheProblems(): array
    {
        try {
            $key = 'preflight-probe-'.bin2hex(random_bytes(4));
            Cache::put($key, 'ok', 10);
            $works = Cache::pull($key) === 'ok';
        } catch (Throwable $exception) {
            return ['Can’t reach the cache ('.$exception->getMessage().'). Rate limits and the live stock feed depend on it.'];
        }

        return $works ? [] : ['The cache isn’t keeping values. Rate limits and the live stock feed depend on it.'];
    }

    /**
     * @return list<string>
     */
    private function settingsProblems(): array
    {
        if (! app()->isProduction()) {
            return [];
        }

        $problems = [];

        if (config('app.debug') === true) {
            $problems[] = 'Debug mode is on, which would show customers error details. Set APP_DEBUG=false.';
        }

        $stripeKey = config('services.stripe.secret');

        if (is_string($stripeKey) && str_starts_with($stripeKey, 'sk_test_')) {
            $problems[] = 'Stripe is in test mode: payments wouldn’t be real. Set STRIPE_SECRET to the live key.';
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
