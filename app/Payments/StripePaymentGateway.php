<?php

namespace App\Payments;

use App\Exceptions\PaymentProviderUnavailable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Exception\ApiErrorException;
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

    public function createCheckoutSession(NewCheckoutSession $session): CheckoutSession
    {
        $metadata = ['order_id' => $session->orderPublicId];

        return $this->checkoutSession(fn (): StripeSession => $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $session->lineItems,
            'client_reference_id' => $session->orderPublicId,
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'phone_number_collection' => ['enabled' => true],
            ...($session->collectShippingAddress ? ['shipping_address_collection' => ['allowed_countries' => ['AU']]] : []),
            'expires_at' => $session->expiresAt->getTimestamp(),
            'success_url' => $session->successUrl,
            'cancel_url' => $session->cancelUrl,
        ], ['idempotency_key' => "checkout-{$session->orderPublicId}"]));
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSession
    {
        return $this->checkoutSession(fn (): StripeSession => $this->stripe->checkout->sessions->retrieve($sessionId));
    }

    public function expireCheckoutSession(string $sessionId): CheckoutSession
    {
        $session = $this->retrieveCheckoutSession($sessionId);

        if ($session->status !== 'open') {
            return $session;
        }

        try {
            return $this->checkoutSession(fn (): StripeSession => $this->stripe->checkout->sessions->expire($sessionId));
        } catch (PaymentProviderUnavailable $exception) {
            // The customer may have paid in the moment between reading and expiring it.
            if ($exception->getPrevious() instanceof InvalidRequestException) {
                return $this->retrieveCheckoutSession($sessionId);
            }

            throw $exception;
        }
    }

    /**
     * @param  Closure(): StripeSession  $request
     *
     * @throws PaymentProviderUnavailable
     */
    private function checkoutSession(Closure $request): CheckoutSession
    {
        try {
            return CheckoutSession::fromStripe($request()->toArray());
        } catch (ApiErrorException $exception) {
            throw new PaymentProviderUnavailable($exception->getMessage(), previous: $exception);
        }
    }
}
