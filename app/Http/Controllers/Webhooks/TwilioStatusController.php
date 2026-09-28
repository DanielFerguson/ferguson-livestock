<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\SmsStatus;
use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Twilio reports each text's progress here: sent to the network, delivered, or not.
 */
class TwilioStatusController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $message = SmsMessage::firstWhere('twilio_sid', $request->string('MessageSid')->value());
        $status = SmsStatus::fromTwilio($request->string('MessageStatus')->value());

        if ($message !== null && $status !== null) {
            $message->recordStatus($status, $request->filled('ErrorCode') ? $request->integer('ErrorCode') : null);
        }

        return response()->noContent();
    }
}
