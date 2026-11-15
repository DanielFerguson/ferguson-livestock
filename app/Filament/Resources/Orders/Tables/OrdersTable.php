<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $timezone = config()->string('shop.timezone');

        return $table
            ->defaultSort(fn (Builder $query, ListOrders $livewire): Builder => self::defaultOrder($query, inRunOrder: $livewire->activeTab === 'to-deliver'), 'desc')
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
                // A row offers it for its own order. The order's page also offers it on one already collected while
                // another of the customer's is waiting.
                OrderActions::markFulfilled()->visible(fn (Order $record): bool => in_array($record->status, OrderStatus::toFulfil(), true)),
                OrderActions::markCashReceived(),
                OrderActions::cancelCashOrder(),
            ])
            ->toolbarActions([
                OrderActions::markSelectedFulfilled(),
            ]);
    }

    /**
     * Newest first, except that To deliver comes in delivery-run order, as the printed run has it: by night,
     * either-night ones last, then by suburb and postcode, with newest first breaking ties. It's the table's default
     * sort rather than part of the tab's query so that a column header picked to sort by comes first, and so that the
     * counts Filament takes from the tab's query carry no ORDER BY, which Postgres refuses alongside count(*).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function defaultOrder(Builder $query, bool $inRunOrder): Builder
    {
        if ($inRunOrder) {
            $query->orderByRaw('delivery_day asc nulls last')->orderByRaw("lower(shipping_address->>'city')")->orderByRaw("shipping_address->>'postal_code'");
        }

        return $query->orderByDesc('created_at');
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
