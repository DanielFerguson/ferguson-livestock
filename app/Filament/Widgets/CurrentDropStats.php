<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Drops\DropResource;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\OrderItem;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

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
            ->heading($drop === null ? 'No drop yet' : $drop->name)
            ->query(DropItem::query()->where('drop_id', $drop->id ?? 0)->with(['product', 'orderItems.order'])->orderBy('id'))
            ->paginated(false)
            ->poll('5s')
            ->columns([
                TextColumn::make('product.name')->label('Item'),
                TextColumn::make('sold')->state(fn (DropItem $record): int => self::quantity($record, [OrderStatus::Paid, OrderStatus::Fulfilled])),
                TextColumn::make('at_checkout')->label('At checkout')->state(fn (DropItem $record): int => self::quantity($record, [OrderStatus::Pending, OrderStatus::Processing])),
                TextColumn::make('left')->state(fn (DropItem $record): ?int => $record->hasOwnStock() ? $record->available : null)->placeholder('—'),
                TextColumn::make('revenue')->state(fn (DropItem $record): string => Money::format(
                    self::lines($record, [OrderStatus::Paid, OrderStatus::Fulfilled])->sum(fn (OrderItem $item): int => $item->lineTotal()),
                )),
            ]);

        if ($drop === null) {
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

    /**
     * @param  list<OrderStatus>  $statuses
     */
    private static function quantity(DropItem $item, array $statuses): int
    {
        return self::lines($item, $statuses)->sum(fn (OrderItem $line): int => $line->quantity);
    }

    /**
     * @param  list<OrderStatus>  $statuses
     * @return Collection<int, OrderItem>
     */
    private static function lines(DropItem $item, array $statuses): Collection
    {
        return $item->orderItems->filter(fn (OrderItem $line): bool => in_array($line->order->status, $statuses, true))->toBase();
    }
}
