<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductType: string implements HasLabel
{
    case Box = 'box';
    case Extra = 'extra';
    case Delivery = 'delivery';

    public function getLabel(): string
    {
        return match ($this) {
            self::Box => 'Beef box',
            self::Extra => 'Individual cut',
            self::Delivery => 'Delivery fee',
        };
    }
}
