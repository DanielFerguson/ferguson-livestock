<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

/**
 * Only let through webhooks Twilio signed with the account's auth token.
 */
class ValidateTwilioSignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.twilio.token');
        $signature = $request->header('X-Twilio-Signature');

        $signedByTwilio = is_string($token) && $token !== '' && is_string($signature)
            && (new RequestValidator($token))->validate($signature, $request->fullUrl(), $request->post());

        abort_unless($signedByTwilio, Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
