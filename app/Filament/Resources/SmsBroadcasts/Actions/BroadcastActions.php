<?php

namespace App\Filament\Resources\SmsBroadcasts\Actions;

use App\Actions\StartBroadcast;
use App\Enums\BroadcastStatus;
use App\Exceptions\SmsNotSent;
use App\Filament\Forms\LocalDateTimePicker;
use App\Models\SmsBroadcast;
use App\Sms\BroadcastPreview;
use App\Sms\QuietHours;
use App\Sms\SmsGateway;
use App\Support\AustralianMobile;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

/**
 * Testing, sending and scheduling a saved broadcast.
 */
final class BroadcastActions
{
    public static function sendTest(): Action
    {
        return Action::make('sendTest')
            ->label('Send a test to me')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('gray')
            ->visible(fn (SmsBroadcast $record): bool => $record->status()->isEditable())
            ->action(function (SmsBroadcast $record, SmsGateway $sms): void {
                $phone = config('shop.admin_phone');

                if (! is_string($phone) || $phone === '') {
                    Notification::make()
                        ->title('Add your mobile number first')
                        ->body('Set SHOP_ADMIN_PHONE to your mobile, like +61412345678, to get test texts.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $sms->send($phone, $record->text()->text);
                } catch (SmsNotSent $exception) {
                    Notification::make()->title('The test didn’t send')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Test sent to '.AustralianMobile::format($phone))->success()->send();
            });
    }

    public static function sendNow(): Action
    {
        return Action::make('sendNow')
            ->label('Send now')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (SmsBroadcast $record): bool => $record->status()->isEditable())
            ->requiresConfirmation()
            ->modalHeading('Send this broadcast now?')
            ->modalDescription(fn (SmsBroadcast $record): string => BroadcastPreview::of($record)->confirmation())
            ->modalSubmitActionLabel('Send')
            ->schema(fn (): array => QuietHours::contains(now()) ? [self::quietHoursCheckbox()] : [])
            ->action(function (SmsBroadcast $record): void {
                if ($record->recipients()->doesntExist()) {
                    Notification::make()->title('Nobody to send to')->body('No one subscribed matches this audience.')->warning()->send();

                    return;
                }

                $queued = app(StartBroadcast::class)($record);

                $queued === null
                    ? Notification::make()->title('This broadcast had already started sending')->warning()->send()
                    : Notification::make()->title('Sending to '.Number::format($queued).' '.str('subscriber')->plural($queued))->success()->send();
            });
    }

    public static function schedule(): Action
    {
        return Action::make('schedule')
            ->label(fn (SmsBroadcast $record): string => $record->scheduled_for !== null ? 'Reschedule' : 'Schedule')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->visible(fn (SmsBroadcast $record): bool => $record->status()->isEditable())
            ->modalHeading('When should it send?')
            ->modalSubmitActionLabel('Schedule')
            ->fillForm(fn (SmsBroadcast $record): array => ['scheduled_for' => $record->scheduled_for ?? $record->drop?->opens_at])
            ->schema([
                LocalDateTimePicker::make('scheduled_for')
                    ->label('Send at')
                    ->required()
                    ->live()
                    ->rule(fn (): Closure => self::inTheFuture()),
                self::quietHoursCheckbox()
                    ->visible(fn (Get $get): bool => self::isQuietTime($get('scheduled_for'))),
            ])
            ->action(function (SmsBroadcast $record, array $data): void {
                $record->update(['scheduled_for' => $data['scheduled_for']]);

                Notification::make()
                    ->title('Scheduled for '.$record->scheduled_for?->setTimezone(QuietHours::TIMEZONE)->format('D j M, g:ia'))
                    ->success()
                    ->send();
            });
    }

    public static function unschedule(): Action
    {
        return Action::make('unschedule')
            ->label('Cancel schedule')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (SmsBroadcast $record): bool => $record->status() === BroadcastStatus::Scheduled)
            ->requiresConfirmation()
            ->modalHeading('Cancel the scheduled send?')
            ->modalDescription('It goes back to being a draft. Nothing is sent.')
            ->action(function (SmsBroadcast $record): void {
                $record->update(['scheduled_for' => null]);

                Notification::make()->title('Back to a draft')->success()->send();
            });
    }

    private static function quietHoursCheckbox(): Checkbox
    {
        return Checkbox::make('send_during_quiet_hours')
            ->label('Send anyway. It’s between 8pm and 8am in Melbourne, when people may be asleep.')
            ->accepted();
    }

    /**
     * The picker's value is Melbourne wall-clock time until it's saved.
     */
    private static function isQuietTime(mixed $localTime): bool
    {
        return is_string($localTime) && $localTime !== ''
            && QuietHours::contains(CarbonImmutable::parse($localTime, QuietHours::TIMEZONE));
    }

    private static function inTheFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && CarbonImmutable::parse($value, QuietHours::TIMEZONE)->lessThanOrEqualTo(now())) {
                $fail('Pick a time in the future.');
            }
        };
    }
}
