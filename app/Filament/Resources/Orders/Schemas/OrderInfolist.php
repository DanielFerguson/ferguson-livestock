<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\AustralianMobile;
use App\Support\CustomerOrders;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;

/**
 * The order's page, laid out for handing over on a phone: what to pack comes first, for every order the customer has
 * on the drop, and the details are folded away below.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $timezone = config()->string('shop.timezone');

        return $schema
            ->columns(1)
            ->components([
                Section::make(fn (Order $record): string => self::itemsHeading($record, CustomerOrders::onDrop($record->drop_id)->of($record)))
                    ->key('customer-orders')
                    ->schema([
                        ViewEntry::make('customer_orders')
                            ->hiddenLabel()
                            ->view('filament.orders.customer-orders')
                            ->state(fn (Order $record): array => self::customerOrders($record)),
                    ]),
                Section::make('Order')
                    ->collapsed()
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
                        TextEntry::make('page')
                            ->label('Customer page')
                            ->state(fn (Order $record): string => $record->pageUrl())
                            ->url(fn (Order $record): string => $record->pageUrl(), shouldOpenInNewTab: true)
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
                Section::make('Customer')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('customer_name')->label('Name')->placeholder('Not given yet'),
                        TextEntry::make('email')->url(fn (Order $record): ?string => $record->email === null ? null : "mailto:{$record->email}")->placeholder('—'),
                        TextEntry::make('phone')
                            ->formatStateUsing(fn (string $state): string => AustralianMobile::readable($state))
                            ->url(fn (Order $record): ?string => $record->phone === null ? null : "tel:{$record->phone}")
                            ->placeholder('—'),
                    ]),
                Section::make('Delivery')
                    ->collapsed()
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
                Section::make('Feedback')
                    ->visible(fn (Order $record): bool => $record->feedback !== null)
                    ->schema([
                        TextEntry::make('feedback_stars')->label('Rating')->state(fn (Order $record): ?string => $record->feedback?->stars()),
                        TextEntry::make('feedback.comment')->label('Comment')->placeholder('No comment'),
                        TextEntry::make('feedback.updated_at')->label('Left')->dateTime('D j M Y, g:ia', $timezone),
                    ]),
                Section::make('Stripe')
                    ->collapsed()
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

    /**
     * "Items" for an order on its own, or "Everything for Mikele · 2 orders" when the customer has more on the drop.
     *
     * @param  Collection<int, Order>  $orders
     */
    private static function itemsHeading(Order $record, Collection $orders): string
    {
        $firstName = $record->firstName();

        return $orders->count() === 1
            ? 'Items'
            : 'Everything for '.(blank($firstName) ? 'this customer' : $firstName).' · '.$orders->count().' orders';
    }

    /**
     * The customer's orders on the drop, this one first, each with what to pack for it. The delivery fee isn't
     * packed, so it's left out. Items are loaded on just these orders, which for an order that wasn't placed is the
     * page's own record: Livewire restores it without its items after the first request.
     *
     * @return array<int, array{reference: string, status: OrderStatus, note: string|null, items: array<int, array{line: string, free: bool}>}>
     */
    private static function customerOrders(Order $record): array
    {
        return CustomerOrders::onDrop($record->drop_id)->of($record)
            ->load('items.product')
            ->map(fn (Order $order): array => [
                'reference' => $order->reference(),
                'status' => $order->status,
                'note' => self::orderNote($order, $record),
                'items' => $order->items
                    ->reject(fn (OrderItem $item): bool => $item->product->type === ProductType::Delivery)
                    ->map(fn (OrderItem $item): array => ['line' => "{$item->quantity} × {$item->product->name}", 'free' => $item->free])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * What else to know about one of the customer's orders, beside its status: that it's the one opened, the cash to
     * collect, the day it was handed over, and how it goes out when that's not how the opened order does.
     */
    private static function orderNote(Order $order, Order $opened): ?string
    {
        $note = array_filter([
            $order->is($opened) ? 'This one' : null,
            $order->status === OrderStatus::CashOwed ? 'Collect '.Money::format($order->total).' cash' : null,
            $order->status === OrderStatus::Fulfilled ? $order->fulfilledDay() : null,
            $order->deliverySummary() !== $opened->deliverySummary() ? $order->deliverySummary() : null,
        ], filled(...));

        return $note === [] ? null : implode(' · ', $note);
    }
}
