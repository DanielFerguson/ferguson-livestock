<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\AustralianMobile;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $timezone = config()->string('shop.timezone');

        return $schema
            ->columns(2)
            ->components([
                Section::make('Order')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->state(fn (Order $record): string => $record->reference()),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('payment_method')->label('Payment'),
                        TextEntry::make('drop.name')->label('Drop'),
                        TextEntry::make('created_at')->label('Placed')->dateTime('D j M Y, g:ia', $timezone),
                        TextEntry::make('paid_at')->label('Paid')->dateTime('D j M Y, g:ia', $timezone)->placeholder('Not yet'),
                        TextEntry::make('fulfilled_at')->label('Fulfilled')->dateTime('D j M Y, g:ia', $timezone)->placeholder('Not yet'),
                        TextEntry::make('total')->formatStateUsing(fn (int $state): string => Money::format($state)),
                        TextEntry::make('amount_refunded')
                            ->label('Refunded')
                            ->formatStateUsing(fn (int $state): string => Money::format($state))
                            ->visible(fn (Order $record): bool => $record->amount_refunded > 0),
                    ]),
                Section::make('Customer')
                    ->schema([
                        TextEntry::make('customer_name')->label('Name')->placeholder('Not given yet'),
                        TextEntry::make('email')->url(fn (Order $record): ?string => $record->email === null ? null : "mailto:{$record->email}")->placeholder('—'),
                        TextEntry::make('phone')
                            ->formatStateUsing(fn (string $state): string => AustralianMobile::readable($state))
                            ->url(fn (Order $record): ?string => $record->phone === null ? null : "tel:{$record->phone}")
                            ->placeholder('—'),
                    ]),
                Section::make('Delivery')
                    ->schema([
                        TextEntry::make('delivery_method')->label('How'),
                        TextEntry::make('delivery_day')->label('Night')->date('l j F')->placeholder('Either night')->visible(fn (Order $record): bool => $record->delivery_method === DeliveryMethod::Delivery),
                        TextEntry::make('address')
                            ->state(fn (Order $record): ?string => $record->shipping_address === null ? null : implode(', ', array_filter([
                                $record->shipping_address['line1'], $record->shipping_address['line2'],
                                trim(($record->shipping_address['city'] ?? '').' '.($record->shipping_address['postal_code'] ?? '')),
                            ], fn (?string $part): bool => $part !== null && $part !== '')))
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => $record->delivery_method === DeliveryMethod::Delivery),
                    ]),
                Section::make('Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(3)
                            ->schema([
                                TextEntry::make('product.name')->hiddenLabel(),
                                TextEntry::make('quantity')->hiddenLabel()->formatStateUsing(fn (int $state): string => "× {$state}"),
                                TextEntry::make('line_total')->hiddenLabel()->state(fn (OrderItem $record): string => $record->free ? 'Free' : Money::format($record->lineTotal())),
                            ]),
                    ]),
                Section::make('Stripe')
                    ->collapsed()
                    ->columnSpanFull()
                    ->visible(fn (Order $record): bool => $record->payment_method === PaymentMethod::Stripe)
                    ->schema([
                        TextEntry::make('stripe_payment_intent_id')
                            ->label('Payment')
                            ->url(fn (Order $record): ?string => $record->stripe_payment_intent_id === null ? null : "https://dashboard.stripe.com/payments/{$record->stripe_payment_intent_id}", shouldOpenInNewTab: true)
                            ->placeholder('Not paid yet'),
                        TextEntry::make('stripe_checkout_session_id')->label('Checkout session')->placeholder('—'),
                    ]),
            ]);
    }
}
