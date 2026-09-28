<?php

namespace Tests\Fakes;

use Twilio\AuthStrategy\AuthStrategy;
use Twilio\Http\Client;
use Twilio\Http\Response;

/**
 * Answers Twilio API calls with one canned response and records each request.
 */
final class FakeTwilioHttpClient implements Client
{
    /** @var list<array{method: string, url: string, data: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(private int $status, private array $body) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $url, array $params = [], array $data = [], array $headers = [], ?string $user = null, ?string $password = null, ?int $timeout = null, ?AuthStrategy $authStrategy = null): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'data' => $data];

        return new Response($this->status, json_encode($this->body, JSON_THROW_ON_ERROR));
    }
}
