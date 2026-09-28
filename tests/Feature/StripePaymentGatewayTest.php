<?php

use App\Payments\StripePaymentGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Fakes\FakeStripeHttpClient;

/**
 * @param  array<string, array{int, array<string, mixed>}>  $responses
 */
function fakeStripeHttp(array $responses): FakeStripeHttpClient
{
    ApiRequestor::setHttpClient($client = new FakeStripeHttpClient($responses));

    return $client;
}

afterEach(fn () => ApiRequestor::setHttpClient(new CurlClient));

beforeEach(fn () => config(['services.stripe.secret' => 'sk_test_fake']));

it('reads the parts of a price that matter for a drop', function () {
    fakeStripeHttp([
        'GET /v1/prices/price_5kg' => [200, [
            'id' => 'price_5kg', 'object' => 'price', 'active' => true, 'type' => 'one_time',
            'currency' => 'aud', 'unit_amount' => 16000,
            'product' => ['id' => 'prod_1', 'object' => 'product', 'name' => '5kg Beef Box'],
        ]],
    ]);

    $price = StripePaymentGateway::fromConfig()->retrievePrice('price_5kg');

    expect($price?->unitAmount)->toBe(16000)
        ->and($price?->currency)->toBe('aud')
        ->and($price?->type)->toBe('one_time')
        ->and($price?->active)->toBeTrue()
        ->and($price?->productName)->toBe('5kg Beef Box');
});

it('returns nothing for a price Stripe does not have', function () {
    fakeStripeHttp([]);

    expect(StripePaymentGateway::fromConfig()->retrievePrice('price_missing'))->toBeNull();
});

it('pins the Stripe API version', function () {
    $http = fakeStripeHttp([]);

    StripePaymentGateway::fromConfig()->retrievePrice('price_missing');

    expect(collect($http->requests[0]['headers'])->contains('Stripe-Version: '.StripePaymentGateway::API_VERSION))->toBeTrue();
});

it('asks Stripe once, then answers from the cache', function () {
    $http = fakeStripeHttp([]);
    $gateway = StripePaymentGateway::fromConfig();

    $gateway->retrievePrice('price_missing');
    $gateway->retrievePrice('price_missing');

    expect($http->requests)->toHaveCount(1);
});

it('lists webhook endpoints and whether they are enabled', function () {
    fakeStripeHttp([
        'GET /v1/webhook_endpoints' => [200, [
            'object' => 'list', 'has_more' => false, 'url' => '/v1/webhook_endpoints',
            'data' => [[
                'id' => 'we_1', 'object' => 'webhook_endpoint', 'status' => 'enabled',
                'url' => 'https://www.fergusonlivestock.com.au/api/webhooks/stripe',
                'enabled_events' => ['checkout.session.completed', 'checkout.session.expired'],
            ]],
        ]],
    ]);

    $endpoints = StripePaymentGateway::fromConfig()->webhookEndpoints();

    expect($endpoints)->toHaveCount(1)
        ->and($endpoints[0]->enabled)->toBeTrue()
        ->and($endpoints[0]->events)->toBe(['checkout.session.completed', 'checkout.session.expired']);
});
