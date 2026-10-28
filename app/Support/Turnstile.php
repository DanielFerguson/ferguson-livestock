<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile, the bot check on the wait-list and order forms.
 *
 * The widget puts a single-use token in the form, and this asks Cloudflare whether it's genuine.
 * If Cloudflare can't be reached, the check is skipped rather than turning real customers away.
 */
class Turnstile
{
    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const string FIELD = 'cf-turnstile-response';

    public const string FAILED_MESSAGE = 'Please wait for the security check above the button to show a tick, then try again.';

    /**
     * Whether both keys are set. Without them the widget isn't shown and every check passes.
     */
    public static function isEnabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    /**
     * Whether Cloudflare says the secret itself is wrong. A made-up token is answered "invalid-input-response" when the
     * secret is right and "invalid-input-secret" when it isn't, which would turn every customer away. If Cloudflare
     * can't be reached this says no, since the bot check also lets people through when it can't be reached.
     */
    public static function secretIsRejected(): bool
    {
        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => 'preflight-probe',
            ]);
        } catch (ConnectionException) {
            return false;
        }

        $errors = $response->json('error-codes');

        return is_array($errors) && in_array('invalid-input-secret', $errors, true);
    }

    public function passes(mixed $token, ?string $ip): bool
    {
        if (! self::isEnabled()) {
            return true;
        }

        // Cloudflare's tokens are at most 2048 characters.
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Turnstile couldn’t be reached, so the bot check was skipped.', ['error' => $exception->getMessage()]);

            return true;
        }

        if ($response->serverError()) {
            Log::warning('Turnstile had an error, so the bot check was skipped.', ['status' => $response->status()]);

            return true;
        }

        return $response->json('success') === true;
    }
}
