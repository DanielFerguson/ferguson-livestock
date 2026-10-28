<?php

declare(strict_types=1);

namespace App\Actions\Broadcasts;

use App\Exceptions\CannotChangeBroadcast;
use App\Exceptions\SmsNotSent;
use App\Models\SmsBroadcast;
use App\Sms\SmsGateway;

/**
 * Texts a broadcast, as subscribers will receive it, to the owner's own mobile. Nothing is recorded.
 */
final readonly class SendBroadcastTest
{
    public function __construct(private SmsGateway $sms) {}

    /**
     * @return string the number it was sent to
     *
     * @throws CannotChangeBroadcast
     */
    public function __invoke(SmsBroadcast $broadcast): string
    {
        $phone = config('shop.admin_phone');

        if (! is_string($phone) || $phone === '') {
            throw new CannotChangeBroadcast('Set SHOP_ADMIN_PHONE to your mobile, like +61412345678, to get test texts.', 'Add your mobile number first');
        }

        try {
            $this->sms->send($phone, $broadcast->text()->text);
        } catch (SmsNotSent $exception) {
            throw new CannotChangeBroadcast($exception->getMessage(), 'The test didn’t send');
        }

        return $phone;
    }
}
