<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderFeedback;

use App\Filament\Resources\OrderFeedback\Pages\ListOrderFeedback;
use App\Filament\Resources\OrderFeedback\Tables\OrderFeedbackTable;
use App\Models\OrderFeedback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * What customers thought of their orders, left on their order pages. Read only: it's theirs.
 */
class OrderFeedbackResource extends Resource
{
    protected static ?string $model = OrderFeedback::class;

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?int $navigationSort = 0;

    public static function table(Table $table): Table
    {
        return OrderFeedbackTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderFeedback::route('/'),
        ];
    }
}
