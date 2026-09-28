<?php

namespace App\Sms;

use App\Exceptions\SmsNotSent;

/**
 * Everything the app asks of the SMS provider. Tests bind a fake in its place.
 */
interface SmsGateway
{
    /**
     * Hand a text to the provider, which reports delivery to the status webhook.
     *
     * @throws SmsNotSent
     */
    public function send(string $to, string $body): SentSms;
}
