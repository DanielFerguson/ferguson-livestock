<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Sms\OptOutKeywords;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Replies to the shop's number arrive here. Replies asking to stop opt the sender out straight away.
 */
class TwilioInboundController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $from = $request->string('From')->value();
        $body = $request->string('Body')->value();
        $subscriber = Subscriber::firstWhere('phone', $from);

        // Twilio sends a reply again if we're slow to answer, so each one is stored once, by its ID.
        SmsMessage::createOrFirst(['twilio_sid' => $request->string('MessageSid')->value()], [
            'subscriber_id' => $subscriber?->id,
            'direction' => SmsDirection::Inbound,
            'to' => $request->string('To')->value(),
            'from' => $from,
            'body' => $body,
            'status' => SmsStatus::Received,
            'segments' => $request->integer('NumSegments', 1),
        ]);

        if (OptOutKeywords::matches($body)) {
            $subscriber?->optOut();
        }

        // An empty TwiML response: no automatic reply. Twilio answers STOP itself.
        return response('<?xml version="1.0" encoding="UTF-8"?><Response/>', Response::HTTP_OK, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
