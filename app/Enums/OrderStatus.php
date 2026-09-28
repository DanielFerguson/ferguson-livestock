<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    /** Stock is held while the customer is at Stripe's payment page. */
    case Pending = 'pending';
    /** Checkout finished with a payment that takes days to clear, like a bank debit. Stock stays held. */
    case Processing = 'processing';
    case Paid = 'paid';
    case Fulfilled = 'fulfilled';
    /** Abandoned, cancelled or never reached Stripe. Stock went back on sale. */
    case Expired = 'expired';
    /** A delayed payment that didn't clear. Stock went back on sale. */
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'At checkout',
            self::Processing => 'Payment clearing',
            self::Paid => 'Paid',
            self::Fulfilled => 'Fulfilled',
            self::Expired => 'Abandoned',
            self::Failed => 'Payment failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'warning',
            self::Paid => 'success',
            self::Fulfilled => 'info',
            self::Expired => 'gray',
            self::Failed => 'danger',
        };
    }
}
