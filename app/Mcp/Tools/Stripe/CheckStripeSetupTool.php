<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Stripe;

use App\Models\StripeEvent;
use App\Payments\PaymentGateway;
use App\Payments\StripeWebhookEvents;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Is Stripe wired up to this site? Checks the webhook endpoint exists, is enabled and sends every event the site needs, and counts events that failed to process.')]
#[IsReadOnly]
#[IsIdempotent]
final class CheckStripeSetupTool extends Tool
{
    public function handle(Request $request, PaymentGateway $payments): Response|ResponseFactory
    {
        try {
            $problems = StripeWebhookEvents::problems($payments->webhookEndpoints(), config()->string('app.url'));
        } catch (Throwable $exception) {
            return Response::error('Could not reach Stripe: '.$exception->getMessage());
        }

        return Response::structured([
            'webhook_ok' => $problems === [],
            'problems' => $problems,
            'required_events' => StripeWebhookEvents::REQUIRED,
            'unprocessed_events' => StripeEvent::query()->whereNull('processed_at')->count(),
            'failing_events' => StripeEvent::query()->whereNull('processed_at')->whereNotNull('last_error')->count(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
