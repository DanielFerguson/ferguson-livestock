<?php

namespace Tests\Fakes;

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
        $this->retrievedPrices[] = $priceId;

        return $this->prices[$priceId] ?? null;
    }

    public function webhookEndpoints(): array
    {
        return $this->endpoints;
    }
}
