<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Stripe's payment news. Each event is checked, stored once by its ID, and processed on the queue. An event
 * that hasn't been processed yet, because its job failed, is queued again whenever Stripe resends it.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, (string) $request->header('Stripe-Signature'), is_string($secret) ? $secret : '');
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        StripeEvent::query()->insertOrIgnore([
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $payload,
            'received_at' => now(),
        ]);

        if (StripeEvent::query()->whereKey($event->id)->whereNull('processed_at')->exists()) {
            ProcessStripeEvent::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }
}
