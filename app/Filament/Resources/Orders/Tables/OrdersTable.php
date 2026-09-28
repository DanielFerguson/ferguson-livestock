<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
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
                TextColumn::make('created_at')->label('Placed')->dateTime('D j M, g:ia', $timezone)->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('customer_name')->label('Customer')->placeholder('—')->searchable(['customer_name', 'email', 'phone']),
                TextColumn::make('delivery')
                    ->state(fn (Order $record): string => $record->delivery_method === DeliveryMethod::Delivery
                        ? 'Delivery, '.($record->delivery_day?->format('D j M') ?? '')
                        : 'Farm pickup')
                    ->description(fn (Order $record): ?string => $record->shipping_address['city'] ?? null),
                TextColumn::make('total')->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd(),
                TextColumn::make('amount_refunded')
                    ->label('Refunded')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(OrderStatus::class)
                    ->default([OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Fulfilled->value]),
                SelectFilter::make('drop')->relationship('drop', 'name'),
                SelectFilter::make('delivery_method')->label('Delivery or pickup')->options(DeliveryMethod::class),
                SelectFilter::make('delivery_day')
                    ->options(fn (): array => Order::query()->whereNotNull('delivery_day')->distinct()->orderByDesc('delivery_day')->limit(20)->get(['delivery_day'])
                        ->mapWithKeys(fn (Order $order): array => [(string) $order->delivery_day?->toDateString() => (string) $order->delivery_day?->format('D j M Y')])
                        ->all()),
            ])
            ->recordActions([
                OrderActions::markFulfilled(),
            ])
            ->toolbarActions([
                OrderActions::markSelectedFulfilled(),
            ]);
    }
}
