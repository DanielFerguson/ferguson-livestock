<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderTexts;

use App\Enums\SmsStatus;
use App\Filament\Resources\OrderTexts\Pages\ListOrderTexts;
use App\Filament\Resources\OrderTexts\Tables\OrderTextsTable;
use App\Models\SmsMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The texts telling customers what they ordered and when it's ready: those waiting for 8am, and those sent.
 */
class OrderTextResource extends Resource
{
    protected static ?string $model = SmsMessage::class;

    protected static ?string $slug = 'order-texts';

    protected static ?string $modelLabel = 'order text';

    protected static ?string $navigationLabel = 'Order texts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Texts';

    protected static ?int $navigationSort = 4;

    /**
     * Texts waiting to go out.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = self::getEloquentQuery()->where('status', SmsStatus::Queued)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_order_confirmation', true)->with('orders');
    }

    public static function table(Table $table): Table
    {
        return OrderTextsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderTexts::route('/'),
        ];
    }
}
