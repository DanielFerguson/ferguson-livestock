<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderTexts\Tables;

use App\Actions\Orders\OrderConfirmationTexts;
use App\Enums\SmsStatus;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Sms\QuietHours;
use App\Sms\TwilioErrors;
use App\Support\AustralianMobile;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

class OrderTextsTable
{
    public static function configure(Table $table): Table
    {
        $timezone = config()->string('shop.timezone');

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Saved')->dateTime('D j M, g:ia', $timezone)->sortable(),
                TextColumn::make('orders')
                    ->label('Order')
                    ->state(fn (SmsMessage $record): string => $record->orders->map(fn (Order $order): string => $order->reference())->implode(', '))
                    ->description(fn (SmsMessage $record): ?string => $record->orders->first()?->customer_name),
                TextColumn::make('to')->label('Mobile')->formatStateUsing(fn (string $state): string => AustralianMobile::readable($state)),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (SmsMessage $record): ?string => match (true) {
                        $record->status === SmsStatus::Queued => QuietHours::contains(now()) ? 'Sends at 8am' : 'Sending now',
                        $record->error_code !== null => TwilioErrors::explain($record->error_code),
                        default => null,
                    }),
                TextColumn::make('sent_at')->label('Sent')->dateTime('D j M, g:ia', $timezone)->placeholder('—'),
                TextColumn::make('body')->label('Text')->wrap()->limit(220)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(SmsStatus::class),
            ])
            ->recordActions([
                self::sendNow(),
            ])
            ->toolbarActions([
                self::sendSelectedNow(),
            ]);
    }

    private static function sendNow(): Action
    {
        return Action::make('sendNow')
            ->label('Send now')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (SmsMessage $record): bool => $record->status === SmsStatus::Queued)
            ->requiresConfirmation()
            ->modalHeading('Send this text now?')
            ->modalDescription(fn (): ?string => QuietHours::contains(now()) ? 'It’s quiet hours, between 8pm and 8am. It goes out straight away rather than at 8am.' : null)
            ->modalSubmitActionLabel('Send now')
            ->action(function (SmsMessage $record, OrderConfirmationTexts $texts): void {
                $texts->sendWaitingNow($record);

                Notification::make()->title('Sending now')->success()->send();
            });
    }

    private static function sendSelectedNow(): BulkAction
    {
        return BulkAction::make('sendNow')
            ->label('Send now')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->requiresConfirmation()
            ->modalHeading('Send the selected texts now?')
            ->modalDescription('Only texts still waiting are sent. Any already sent are left alone.')
            ->modalSubmitActionLabel('Send now')
            ->action(function (Collection $records, OrderConfirmationTexts $texts): void {
                $sent = $records->filter(fn (Model $message): bool => $message instanceof SmsMessage && $texts->sendWaitingNow($message))->count();

                Notification::make()->title(Number::format($sent).' '.str('text')->plural($sent).' sending now')->success()->send();
            });
    }
}
