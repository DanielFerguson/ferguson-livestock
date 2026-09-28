<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Worked out from a drop's timestamps and stock, never stored, so a drop goes live on time without a scheduled job.
 */
enum DropStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Announced = 'announced';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case SoldOut = 'sold_out';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Announced => 'Announced',
            self::Scheduled => 'Scheduled',
            self::Live => 'Live',
            self::SoldOut => 'Sold out',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Announced => 'primary',
            self::Scheduled => 'info',
            self::Live => 'success',
            self::SoldOut => 'warning',
            self::Closed => 'danger',
        };
    }

    /**
     * Customers can order (Live) or are waiting on released holds (SoldOut).
     */
    public function isOpen(): bool
    {
        return $this === self::Live || $this === self::SoldOut;
    }
}
