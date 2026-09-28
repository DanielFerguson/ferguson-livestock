<?php

namespace App\Payments;

use Illuminate\Support\Facades\Cache;
use Stripe\Exception\InvalidRequestException;
use Stripe\Product as StripeProduct;
use Stripe\StripeClient;

final class StripePaymentGateway implements PaymentGateway
{
    /**
     * Pinned so a Stripe SDK upgrade never silently changes request or webhook shapes.
     */
    public const string API_VERSION = '2026-08-26.dahlia';

    public function __construct(private readonly StripeClient $stripe) {}

    public static function fromConfig(): self
    {
        return new self(new StripeClient([
            'api_key' => config()->string('services.stripe.secret'),
            'stripe_version' => self::API_VERSION,
            'max_network_retries' => 2,
        ]));
    }

    /**
     * Cached for five minutes, so re-checking a form or a pre-flight doesn't hammer Stripe.
     * The cache holds plain arrays because Laravel 13 refuses to unserialize objects from it.
     */
    public function retrievePrice(string $priceId): ?Price
    {
        /** @var array{id: string, active: bool, type: string, currency: string, unitAmount: int|null, productName: string|null}|false $price */
        $price = Cache::remember("stripe:price:{$priceId}", now()->addMinutes(5), function () use ($priceId): array|false {
            try {
                $price = $this->stripe->prices->retrieve($priceId, ['expand' => ['product']]);
            } catch (InvalidRequestException $exception) {
                if ($exception->getStripeCode() === 'resource_missing') {
                    return false;
                }

                throw $exception;
            }

            return [
                'id' => $price->id,
                'active' => (bool) $price->active,
                'type' => (string) $price->type,
                'currency' => (string) $price->currency,
                'unitAmount' => $price->unit_amount,
                'productName' => $price->product instanceof StripeProduct ? $price->product->name : null,
            ];
        });

        return $price === false ? null : new Price(...$price);
    }

    public function webhookEndpoints(): array
    {
        $endpoints = [];

        foreach ($this->stripe->webhookEndpoints->all(['limit' => 100])->autoPagingIterator() as $endpoint) {
            $endpoints[] = new WebhookEndpoint(
                url: (string) $endpoint->url,
                enabled: $endpoint->status === 'enabled',
                events: array_values(array_map(strval(...), (array) $endpoint->enabled_events)),
            );
        }

        return $endpoints;
    }
}
