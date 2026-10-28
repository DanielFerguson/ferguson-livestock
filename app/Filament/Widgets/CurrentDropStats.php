<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Drops\DropResource;
use App\Models\Drop;
use App\Models\DropItem;
use App\Stock\DropSales;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * How the current drop is selling, item by item. Refreshes every five seconds during a drop.
 */
class CurrentDropStats extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public function table(Table $table): Table
    {
        $drop = Drop::featured();

        $table
            ->heading($drop instanceof Drop ? $drop->name : 'No drop yet')
            ->query(DropItem::query()->where('drop_id', $drop->id ?? 0)->with(['product', 'orderItems.order'])->orderBy('id'))
            ->paginated(false)
            ->poll('5s')
            ->columns([
                TextColumn::make('product.name')->label('Item'),
                TextColumn::make('sold')->state(fn (DropItem $record): int => DropSales::forItem($record)['sold']),
                TextColumn::make('at_checkout')->label('At checkout')->state(fn (DropItem $record): int => DropSales::forItem($record)['at_checkout']),
                TextColumn::make('left')->state(fn (DropItem $record): ?int => DropSales::forItem($record)['left'])->placeholder('—'),
                TextColumn::make('revenue')->state(fn (DropItem $record): string => Money::format(DropSales::forItem($record)['revenue'])),
            ]);

        if (! $drop instanceof Drop) {
            $table
                ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
                ->emptyStateHeading('Sales for the current drop show here')
                ->emptyStateDescription('Create a drop to start taking orders.')
                ->emptyStateActions([
                    Action::make('createDrop')
                        ->label('Create a drop')
                        ->icon(Heroicon::OutlinedPlus)
                        ->url(DropResource::getUrl('create')),
                ]);
        }

        return $table;
    }
}
