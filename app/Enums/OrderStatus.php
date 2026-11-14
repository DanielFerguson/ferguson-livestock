<?php

declare(strict_types=1);

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
    /** Recorded by hand for family or friends; stock stays held until the cash is paid or the order is cancelled. */
    case CashOwed = 'cash_owed';

    /**
     * Real orders: paid for, being paid for, or put aside for cash.
     *
     * @return list<self>
     */
    public static function placed(): array
    {
        return [self::Paid, self::Processing, self::Fulfilled, self::CashOwed];
    }

    /**
     * Orders still to pack and deliver: paid ones, and cash ones put aside for friends.
     *
     * @return list<self>
     */
    public static function toFulfil(): array
    {
        return [self::Paid, self::CashOwed];
    }

    /**
     * Checkouts that haven't turned into an order, or never will.
     *
     * @return list<self>
     */
    public static function notPaid(): array
    {
        return [self::Pending, self::Expired, self::Failed];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'At checkout',
            self::Processing => 'Payment clearing',
            self::Paid => 'Paid',
            self::Fulfilled => 'Fulfilled',
            self::Expired => 'Abandoned',
            self::Failed => 'Payment failed',
            self::CashOwed => 'Cash owed',
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
            self::CashOwed => 'warning',
        };
    }
}
