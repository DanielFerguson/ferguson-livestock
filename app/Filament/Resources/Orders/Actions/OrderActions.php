<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Checkout\OrderPayments;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Drop;
use App\Models\Order;
use App\Support\DeliveryRun;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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
            ->action(fn (array $data) => redirect(OrderResource::getUrl('delivery-run', ['drop' => $data['drop']])));
    }

    private static function dropSelect(): Select
    {
        return Select::make('drop')
            ->label('Drop')
            ->options(fn (): array => Drop::query()->orderByDesc('opens_at')->limit(12)->pluck('name', 'id')->all())
            ->default(fn (): ?int => Drop::featured()?->id)
            ->required();
    }
}
