<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    /** Paid on Stripe's payment page: every order customers place on the website. */
    case Stripe = 'stripe';
    /** Paid to the owner in person. Only for family and friends, recorded by hand. */
    case Cash = 'cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Cash => 'Cash',
        };
    }
}
