<?php

namespace Tests\Fakes;

use App\Exceptions\PaymentProviderUnavailable;
use App\Payments\CheckoutSession;
use App\Payments\NewCheckoutSession;
use App\Payments\PaymentGateway;
use App\Payments\Price;
use App\Payments\WebhookEndpoint;

/**
 * An in-memory payment provider: add the prices and webhook endpoints a test needs.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, Price> */
    private array $prices = [];

    /** @var list<WebhookEndpoint> */
    private array $endpoints = [];

    /** @var list<string> */
    public array $retrievedPrices = [];

    /** @var array<string, CheckoutSession> */
    private array $sessions = [];

    /** @var list<NewCheckoutSession> */
    public array $createdSessions = [];

    private ?string $offline = null;

    public function addPrice(string $id, int $unitAmount, bool $active = true, string $type = 'one_time', string $currency = 'aud', ?string $productName = null): self
    {
        $this->prices[$id] = new Price($id, $active, $type, $currency, $unitAmount, $productName);

        return $this;
    }

    /**
     * @param  list<string>  $events
     */
    public function addWebhookEndpoint(string $url, array $events, bool $enabled = true): self
    {
        $this->endpoints[] = new WebhookEndpoint($url, $enabled, $events);

        return $this;
    }

    public function retrievePrice(string $priceId): ?Price
    {
        $this->failIfOffline();
        $this->retrievedPrices[] = $priceId;

        return $this->prices[$priceId] ?? null;
    }

    public function webhookEndpoints(): array
    {
        $this->failIfOffline();

        return $this->endpoints;
    }

    /**
     * Put a fresh fake in the container in place of Stripe, and return it.
     */
    public static function swap(): self
    {
        app()->instance(PaymentGateway::class, $fake = new self);

        return $fake;
    }

    /**
     * Make every call fail, as if Stripe were down.
     */
    public function goOffline(string $reason = 'Stripe is unreachable.'): self
    {
        $this->offline = $reason;

        return $this;
    }

    public function createCheckoutSession(NewCheckoutSession $session): CheckoutSession
    {
        $this->failIfOffline();
        $this->createdSessions[] = $session;
        $id = 'cs_test_'.count($this->createdSessions);

        return $this->sessions[$id] = new CheckoutSession($id, 'open', 'unpaid', "https://checkout.stripe.test/{$id}", $session->orderPublicId);
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSession
    {
        $this->failIfOffline();

        return $this->sessions[$sessionId] ?? throw new PaymentProviderUnavailable("No such checkout session: {$sessionId}");
    }

    public function expireCheckoutSession(string $sessionId): CheckoutSession
    {
        $session = $this->retrieveCheckoutSession($sessionId);

        if ($session->status !== 'open') {
            return $session;
        }

        return $this->sessions[$sessionId] = new CheckoutSession($sessionId, 'expired', 'unpaid', null, $session->orderPublicId);
    }

    /**
     * The customer finished paying. A bank debit finishes 'unpaid' and clears days later.
     */
    public function completeSession(string $sessionId, string $paymentStatus = 'paid', string $name = 'Jane Citizen', string $email = 'jane@example.com'): CheckoutSession
    {
        $session = $this->retrieveCheckoutSession($sessionId);

        return $this->sessions[$sessionId] = new CheckoutSession(
            id: $sessionId,
            status: 'complete',
            paymentStatus: $paymentStatus,
            orderPublicId: $session->orderPublicId,
            paymentIntentId: 'pi_'.substr($sessionId, 8),
            customerName: $name,
            email: $email,
            phone: '+61412345678',
            shippingAddress: ['line1' => '1 Main St', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU'],
        );
    }

    /**
     * Stripe closed the payment page after its time ran out.
     */
    public function timeOutSession(string $sessionId): CheckoutSession
    {
        return $this->expireCheckoutSession($sessionId);
    }

    public function session(string $sessionId): CheckoutSession
    {
        return $this->sessions[$sessionId];
    }

    private function failIfOffline(): void
    {
        if ($this->offline !== null) {
            throw new PaymentProviderUnavailable($this->offline);
        }
    }
}
