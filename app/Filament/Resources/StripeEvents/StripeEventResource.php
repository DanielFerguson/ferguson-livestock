<?php

namespace App\Filament\Resources\StripeEvents;

use App\Filament\Resources\StripeEvents\Pages\ListStripeEvents;
use App\Filament\Resources\StripeEvents\Tables\StripeEventsTable;
use App\Models\StripeEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every webhook Stripe has sent, to check an order's history or retry an event that failed.
 */
class StripeEventResource extends Resource
{
    protected static ?string $model = StripeEvent::class;

    protected static ?string $modelLabel = 'Stripe event';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 9;

    public static function table(Table $table): Table
    {
        return StripeEventsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStripeEvents::route('/'),
        ];
    }
}
