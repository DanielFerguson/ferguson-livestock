<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Actions\Orders\GiveFreeItems;
use App\Actions\Orders\TakeBackFreeItem;
use App\Checkout\OrderPayments;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\CannotChangeOrder;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Stock\DropSales;
use App\Support\DeliveryRun;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderActions
{
    public static function markFulfilled(): Action
    {
        return Action::make('markFulfilled')
            ->label('Mark fulfilled')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Paid)
            ->action(function (Order $record, OrderPayments $orders): void {
                $orders->markFulfilled($record);

                Notification::make()->title('Marked fulfilled')->success()->send();
            });
    }

    public static function markCashReceived(): Action
    {
        return Action::make('markCashReceived')
            ->label('Cash received')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::CashOwed)
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record): string => 'Got '.Money::format($record->total).' from '.($record->customer_name ?? 'them').'?')
            ->modalSubmitActionLabel('Yes, mark paid')
            ->action(function (Order $record, OrderPayments $orders): void {
                $orders->markCashReceived($record);

                Notification::make()->title('Marked paid')->success()->send();
            });
    }

    public static function cancelCashOrder(): Action
    {
        return Action::make('cancelCashOrder')
            ->label('Cancel order')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::CashOwed)
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record): string => 'Cancel '.($record->customer_name ?? 'this').'’s order?')
            ->modalDescription('Its stock goes back on sale on the website.')
            ->modalSubmitActionLabel('Cancel order')
            ->action(function (Order $record, OrderPayments $orders): void {
                $orders->cancelCashOrder($record);

                Notification::make()->title('Order cancelled')->body('Its stock is back on sale.')->success()->send();
            });
    }

    public static function giveFreeItem(): Action
    {
        return Action::make('giveFreeItem')
            ->label('Add free item')
            ->icon(Heroicon::OutlinedGift)
            ->color('gray')
            ->visible(fn (Order $record): bool => GiveFreeItems::canTakeFreeItems($record))
            ->modalHeading(fn (Order $record): string => 'Give '.($record->customer_name ?? 'them').' something free')
            ->modalDescription('It comes out of the drop’s stock. What they paid doesn’t change, and they aren’t told.')
            ->schema([
                Select::make('drop_item_id')
                    ->label('Item')
                    ->options(fn (Order $record): array => self::freeItemOptions($record))
                    ->required(),
                TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
            ])
            ->modalSubmitActionLabel('Add to order')
            ->action(function (array $data, Order $record, GiveFreeItems $giveFreeItems, Action $action): void {
                $dropItemId = is_numeric($data['drop_item_id'] ?? null) ? (int) $data['drop_item_id'] : 0;
                $quantity = is_numeric($data['quantity'] ?? null) ? (int) $data['quantity'] : 0;

                try {
                    $giveFreeItems($record, [$dropItemId => $quantity]);

                    Notification::make()->title('Free item added')->body('It’s come out of the drop’s stock.')->success()->send();
                } catch (CannotChangeOrder $exception) {
                    Notification::make()->title('Not added')->body($exception->getMessage())->danger()->send();

                    $action->halt();
                }
            });
    }

    public static function takeBackFreeItem(): Action
    {
        return Action::make('takeBackFreeItem')
            ->label('Remove free item')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Order $record): bool => $record->released_at === null && self::freeLines($record) !== [])
            ->modalHeading('Remove a free item?')
            ->modalDescription('It goes back on sale on the website.')
            ->schema([
                Select::make('order_item_id')
                    ->label('Item')
                    ->options(fn (Order $record): array => self::freeLines($record))
                    ->default(fn (Order $record): ?int => count(self::freeLines($record)) === 1 ? array_key_first(self::freeLines($record)) : null)
                    ->required(),
            ])
            ->modalSubmitActionLabel('Remove')
            ->action(function (array $data, Order $record, TakeBackFreeItem $takeBackFreeItem, Action $action): void {
                $line = $record->items()->whereKey(is_numeric($data['order_item_id'] ?? null) ? (int) $data['order_item_id'] : 0)->first();

                try {
                    if (! $line instanceof OrderItem) {
                        throw new CannotChangeOrder('That item isn’t on this order.');
                    }

                    $takeBackFreeItem($line);
                    $record->refresh()->load('items.product');

                    Notification::make()->title('Free item removed')->body('It’s back on sale.')->success()->send();
                } catch (CannotChangeOrder $exception) {
                    Notification::make()->title('Not removed')->body($exception->getMessage())->danger()->send();

                    $action->halt();
                }
            });
    }

    public static function markSelectedFulfilled(): BulkAction
    {
        return BulkAction::make('markFulfilled')
            ->label('Mark fulfilled')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->requiresConfirmation()
            ->modalDescription('Only paid orders change. Orders whose payment is still clearing are left as they are.')
            ->action(function (Collection $records, OrderPayments $orders): void {
                $fulfilled = $records->filter(fn (Model $order): bool => $order instanceof Order && $orders->markFulfilled($order))->count();

                Notification::make()->title(Number::format($fulfilled).' '.str('order')->plural($fulfilled).' marked fulfilled')->success()->send();
            });
    }

    public static function downloadDeliveryRun(): Action
    {
        return Action::make('downloadDeliveryRun')
            ->label('Download delivery run')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->schema([self::dropSelect()])
            ->modalSubmitActionLabel('Download')
            ->action(function (array $data): StreamedResponse {
                $drop = Drop::query()->findOrFail(is_numeric($data['drop'] ?? null) ? (int) $data['drop'] : 0);
                $csv = DeliveryRun::forDrop($drop)->toCsv();

                return response()->streamDownload(function () use ($csv): void {
                    echo $csv;
                }, 'delivery-run-'.str($drop->name)->slug().'.csv', ['Content-Type' => 'text/csv']);
            });
    }

    public static function printDeliveryRun(): Action
    {
        return Action::make('printDeliveryRun')
            ->label('Print delivery run')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->schema([self::dropSelect()])
            ->modalSubmitActionLabel('Open')
            ->action(fn (array $data): RedirectResponse => redirect()->to(OrderResource::getUrl('delivery-run', ['drop' => $data['drop']])));
    }

    /**
     * What the order's drop sells, with what's left: "Sausage Pack (6) · 12 left".
     *
     * @return array<int, string>
     */
    private static function freeItemOptions(Order $order): array
    {
        $drop = $order->drop()->with('items.product.bundle')->firstOrFail();

        return $drop->items
            ->reject(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery)
            ->sortBy(fn (DropItem $item): int => $item->product->sort)
            ->mapWithKeys(fn (DropItem $item): array => [$item->id => "{$item->product->name} · ".DropSales::left($drop, $item).' left'])
            ->all();
    }

    /**
     * The order's free items: "2 × Sausage Pack (6)".
     *
     * @return array<int, string>
     */
    private static function freeLines(Order $order): array
    {
        return $order->loadMissing('items.product')->items
            ->filter(fn (OrderItem $line): bool => $line->free)
            ->mapWithKeys(fn (OrderItem $line): array => [$line->id => "{$line->quantity} × {$line->product->name}"])
            ->all();
    }

    private static function dropSelect(): Select
    {
        return Select::make('drop')
            ->label('Drop')
            ->options(fn (): array => Drop::query()->latest('opens_at')->limit(12)->pluck('name', 'id')->all())
            ->default(fn (): ?int => Drop::featured()?->id)
            ->required();
    }
}
