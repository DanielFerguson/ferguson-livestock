<?php

namespace Tests\Fakes;

use Stripe\HttpClient\ClientInterface;

/**
 * Answers Stripe API calls from canned responses, keyed by "METHOD path", and records each request.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, array{int, array<string, mixed>}>  $responses
     */
    public function __construct(private array $responses) {}

    /**
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $params
     * @return array{string, int, array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => strtoupper($method), 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        $key = strtoupper($method).' '.parse_url($absUrl, PHP_URL_PATH);

        [$status, $body] = $this->responses[$key] ?? [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such price']]];

        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }
}
