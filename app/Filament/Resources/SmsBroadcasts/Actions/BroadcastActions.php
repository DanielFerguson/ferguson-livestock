<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsBroadcasts\Actions;

use App\Actions\Broadcasts\ScheduleBroadcast;
use App\Actions\Broadcasts\SendBroadcastNow;
use App\Actions\Broadcasts\SendBroadcastTest;
use App\Actions\Broadcasts\UnscheduleBroadcast;
use App\Enums\BroadcastStatus;
use App\Exceptions\CannotChangeBroadcast;
use App\Filament\Forms\LocalDateTimePicker;
use App\Models\SmsBroadcast;
use App\Sms\BroadcastPreview;
use App\Sms\QuietHours;
use App\Support\AustralianMobile;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
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
            ->action(function (SmsBroadcast $record, SendBroadcastTest $test): void {
                try {
                    $phone = $test($record);
                } catch (CannotChangeBroadcast $exception) {
                    Notification::make()->title($exception->title)->body($exception->getMessage())->warning()->send();

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
            ->action(function (SmsBroadcast $record, array $data): void {
                try {
                    $queued = app(SendBroadcastNow::class)($record, (bool) ($data['send_during_quiet_hours'] ?? false));
                } catch (CannotChangeBroadcast $exception) {
                    Notification::make()->title($exception->title)->body($exception->getMessage())->warning()->send();

                    return;
                }

                Notification::make()->title('Sending to '.Number::format($queued).' '.str('subscriber')->plural($queued))->success()->send();
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
                $at = CarbonImmutable::parse(is_string($data['scheduled_for']) || $data['scheduled_for'] instanceof DateTimeInterface ? $data['scheduled_for'] : 'now', 'UTC');

                try {
                    app(ScheduleBroadcast::class)($record, $at, (bool) ($data['send_during_quiet_hours'] ?? false));
                } catch (CannotChangeBroadcast $exception) {
                    Notification::make()->title($exception->title)->body($exception->getMessage())->warning()->send();

                    return;
                }

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
                app(UnscheduleBroadcast::class)($record);

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

    /**
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private static function inTheFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && CarbonImmutable::parse($value, QuietHours::TIMEZONE)->lessThanOrEqualTo(now())) {
                $fail('Pick a time in the future.');
            }
        };
    }
}
