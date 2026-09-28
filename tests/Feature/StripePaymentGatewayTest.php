<?php

use App\Exceptions\PaymentProviderUnavailable;
use App\Payments\NewCheckoutSession;
use App\Payments\StripePaymentGateway;
use Carbon\CarbonImmutable;
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

/**
 * A Checkout Session as the pinned API version returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSession(array $overrides = []): array
{
    return array_merge([
        'id' => 'cs_test_123', 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_123', 'client_reference_id' => '01J9ORDER', 'metadata' => ['order_id' => '01J9ORDER'],
        'payment_intent' => null, 'amount_total' => 19000, 'customer_details' => null, 'collected_information' => null,
    ], $overrides);
}

it('starts a Checkout Session that can only be created once per order', function () {
    $http = fakeStripeHttp(['POST /v1/checkout/sessions' => [200, stripeSession()]]);

    $session = StripePaymentGateway::fromConfig()->createCheckoutSession(new NewCheckoutSession(
        orderPublicId: '01J9ORDER',
        lineItems: [['price' => 'price_box', 'quantity' => 1], ['price' => 'price_delivery', 'quantity' => 1]],
        collectShippingAddress: true,
        expiresAt: CarbonImmutable::parse('2026-10-01 12:31:00', 'UTC'),
        successUrl: 'https://example.test/order-confirmed?session_id={CHECKOUT_SESSION_ID}',
        cancelUrl: 'https://example.test/checkout/cancel?session_id={CHECKOUT_SESSION_ID}',
    ));

    $request = $http->requests[0];
    expect($session->id)->toBe('cs_test_123')
        ->and($session->url)->toBe('https://checkout.stripe.com/c/pay/cs_test_123')
        ->and($request['headers'])->toContain('Idempotency-Key: checkout-01J9ORDER')
        ->and($request['params'])->toMatchArray([
            'mode' => 'payment',
            'line_items' => [['price' => 'price_box', 'quantity' => 1], ['price' => 'price_delivery', 'quantity' => 1]],
            'client_reference_id' => '01J9ORDER',
            'metadata' => ['order_id' => '01J9ORDER'],
            'payment_intent_data' => ['metadata' => ['order_id' => '01J9ORDER']],
            'phone_number_collection' => ['enabled' => true],
            'shipping_address_collection' => ['allowed_countries' => ['AU']],
            'expires_at' => CarbonImmutable::parse('2026-10-01 12:31:00', 'UTC')->getTimestamp(),
            'success_url' => 'https://example.test/order-confirmed?session_id={CHECKOUT_SESSION_ID}',
        ]);
});

it('doesn’t ask for an address for farm pickup', function () {
    $http = fakeStripeHttp(['POST /v1/checkout/sessions' => [200, stripeSession()]]);

    StripePaymentGateway::fromConfig()->createCheckoutSession(new NewCheckoutSession('01J9ORDER', [['price' => 'price_box', 'quantity' => 1]], false, now()->addMinutes(31)->toImmutable(), 'https://example.test/s', 'https://example.test/c'));

    expect($http->requests[0]['params'])->not->toHaveKey('shipping_address_collection');
});

it('reads who paid and where to deliver from a completed session', function () {
    fakeStripeHttp(['GET /v1/checkout/sessions/cs_test_123' => [200, stripeSession([
        'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_123',
        'customer_details' => ['name' => 'Jane Citizen', 'email' => 'jane@example.com', 'phone' => '+61412345678'],
        'collected_information' => ['shipping_details' => ['name' => 'Jane Citizen', 'address' => [
            'line1' => '1 Main St', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU',
        ]]],
    ])]]);

    $session = StripePaymentGateway::fromConfig()->retrieveCheckoutSession('cs_test_123');

    expect($session->isPaid())->toBeTrue()
        ->and($session->orderPublicId)->toBe('01J9ORDER')
        ->and($session->paymentIntentId)->toBe('pi_123')
        ->and($session->customerName)->toBe('Jane Citizen')
        ->and($session->email)->toBe('jane@example.com')
        ->and($session->phone)->toBe('+61412345678')
        ->and($session->shippingAddress)->toBe(['line1' => '1 Main St', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU']);
});

it('expires an open session', function () {
    $http = fakeStripeHttp([
        'GET /v1/checkout/sessions/cs_test_123' => [200, stripeSession()],
        'POST /v1/checkout/sessions/cs_test_123/expire' => [200, stripeSession(['status' => 'expired'])],
    ]);

    expect(StripePaymentGateway::fromConfig()->expireCheckoutSession('cs_test_123')->isExpired())->toBeTrue()
        ->and($http->requests)->toHaveCount(2);
});

it('leaves a session that’s already complete, and says so', function () {
    $http = fakeStripeHttp(['GET /v1/checkout/sessions/cs_test_123' => [200, stripeSession(['status' => 'complete', 'payment_status' => 'paid'])]]);

    expect(StripePaymentGateway::fromConfig()->expireCheckoutSession('cs_test_123')->isPaid())->toBeTrue()
        ->and($http->requests)->toHaveCount(1);
});

it('reports Stripe being unreachable', function () {
    fakeStripeHttp(['POST /v1/checkout/sessions' => [500, ['error' => ['type' => 'api_error', 'message' => 'Something went wrong']]]]);

    StripePaymentGateway::fromConfig()->createCheckoutSession(new NewCheckoutSession('01J9ORDER', [['price' => 'price_box', 'quantity' => 1]], false, now()->addMinutes(31)->toImmutable(), 'https://example.test/s', 'https://example.test/c'));
})->throws(PaymentProviderUnavailable::class);
