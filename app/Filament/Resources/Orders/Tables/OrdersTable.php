<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\DeliveryMethod;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $timezone = config()->string('shop.timezone');

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('public_id')
                    ->label('Order')
                    ->formatStateUsing(fn (Order $record): string => $record->reference())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('public_id', 'like', '%'.strtolower(str_replace('FL-', '', strtoupper($search))).'%')),
                TextColumn::make('created_at')->label('Placed')->dateTime('D j M, g:ia', $timezone)->sortable()->visibleFrom('md'),
                TextColumn::make('status')->badge(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->placeholder('—')
                    ->searchable(['customer_name', 'email', 'phone'])
                    ->description(fn (Order $record, ListOrders $livewire): ?string => self::customerNote($record, $livewire->customerOrderCount($record))),
                TextColumn::make('delivery')
                    ->state(fn (Order $record): string => $record->deliverySummary())
                    ->description(fn (Order $record): ?string => $record->shipping_address['city'] ?? null)
                    ->visibleFrom('md'),
                TextColumn::make('total')->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd()->visibleFrom('md'),
                TextColumn::make('amount_refunded')
                    ->label('Refunded')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('drop')->relationship('drop', 'name'),
                SelectFilter::make('delivery_method')->label('Delivery or pickup')->options(DeliveryMethod::class),
                SelectFilter::make('delivery_day')
                    ->options(fn (): array => Order::query()->whereNotNull('delivery_day')->distinct()->orderByDesc('delivery_day')->limit(20)->get(['delivery_day'])
                        ->mapWithKeys(fn (Order $order): array => [(string) $order->delivery_day?->toDateString() => (string) $order->delivery_day?->format('D j M Y')])
                        ->all()),
            ])
            ->recordActions([
                OrderActions::markFulfilled(),
                OrderActions::markCashReceived(),
                OrderActions::cancelCashOrder(),
            ])
            ->toolbarActions([
                OrderActions::markSelectedFulfilled(),
            ]);
    }

    /**
     * What to know at a glance, under the customer's name: "2 orders" when they have more than one on the drop, and
     * a delivery's night and suburb, since the Delivery column doesn't fit on a phone.
     */
    private static function customerNote(Order $order, int $ordersOnDrop): ?string
    {
        $note = array_filter([
            $ordersOnDrop > 1 ? "{$ordersOnDrop} orders" : null,
            $order->deliveryNightShort(),
            $order->delivery_method === DeliveryMethod::Delivery ? ($order->shipping_address['city'] ?? null) : null,
        ], filled(...));

        return $note === [] ? null : implode(' · ', $note);
    }
}
