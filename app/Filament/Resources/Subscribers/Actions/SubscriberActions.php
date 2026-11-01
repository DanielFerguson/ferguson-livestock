<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Actions;

use App\Jobs\SendSignUpConfirmation;
use App\Models\Subscriber;
use App\Sms\BroadcastPreview;
use App\Sms\QuietHours;
use App\Sms\SmsText;
use App\Support\SpreadsheetCell;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The subscriber actions shared by the table and the edit page.
 */
final class SubscriberActions
{
    private const array CSV_COLUMNS = ['first_name', 'phone', 'postcode', 'subscribed', 'consented_at', 'consent_source', 'consent_wording', 'consent_ip', 'unsubscribed_at'];

    public static function optOut(): Action
    {
        return Action::make('optOut')
            ->label('Opt out')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Subscriber $record): bool => $record->isSubscribed())
            ->requiresConfirmation()
            ->modalHeading('Stop texting this person?')
            ->modalDescription('They won’t get any more broadcasts. To start again, they need to text START to the shop’s number from their phone, which records their consent again.')
            ->modalSubmitActionLabel('Opt them out')
            ->action(function (Subscriber $record): void {
                $record->optOut();

                Notification::make()->title('Opted out')->success()->send();
            });
    }

    /**
     * Send the wait-list confirmation to someone who hasn't had one, like people who joined before these were sent.
     */
    public static function sendConfirmation(): Action
    {
        return Action::make('sendConfirmation')
            ->label('Send text')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->visible(fn (Subscriber $record): bool => $record->canBeSentConfirmation())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading('Send the confirmation text?')
            ->modalDescription(fn (): string => 'They’ll get this text'.(QuietHours::contains(now()) ? ' after 8am' : '').': '.SmsText::forBroadcast(SendSignUpConfirmation::BODY)->text)
            ->modalSubmitActionLabel('Send text')
            ->action(function (Subscriber $record): void {
                SendSignUpConfirmation::queueIfNeeded($record);

                Notification::make()
                    ->title(QuietHours::contains(now()) ? 'Confirmation text queued for 8am' : 'Confirmation text on its way')
                    ->success()
                    ->send();
            });
    }

    /**
     * The same text for everyone selected. Anyone who has opted out or already has one is left out.
     */
    public static function sendConfirmations(): BulkAction
    {
        return BulkAction::make('sendConfirmations')
            ->label('Send confirmation text')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading('Send the confirmation text?')
            ->modalDescription(fn (Collection $records): string => self::describeBulkSend($records))
            ->modalSubmitActionLabel('Send texts')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $queued = $records->filter(fn (Model $subscriber): bool => $subscriber instanceof Subscriber && SendSignUpConfirmation::queueIfNeeded($subscriber))->count();

                if ($queued === 0) {
                    Notification::make()->title('Nobody selected can have a confirmation text')->warning()->send();

                    return;
                }

                $texts = Number::format($queued).' confirmation '.str('text')->plural($queued);

                Notification::make()
                    ->title(QuietHours::contains(now()) ? "{$texts} queued for 8am" : "{$texts} on ".($queued === 1 ? 'its' : 'their').' way')
                    ->success()
                    ->send();
            });
    }

    /**
     * Everyone on the list, with the evidence of their consent, as a spreadsheet.
     */
    public static function downloadCsv(): Action
    {
        return Action::make('downloadCsv')
            ->label('Download CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (): StreamedResponse => response()->streamDownload(function (): void {
                $file = fopen('php://output', 'w');

                if ($file === false) {
                    return;
                }

                fputcsv($file, self::CSV_COLUMNS, escape: '');

                Subscriber::query()->orderBy('id')->lazy()->each(fn (Subscriber $subscriber): int|false => fputcsv($file, self::csvRow($subscriber), escape: ''));

                fclose($file);
            }, 'subscribers-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']));
    }

    /**
     * Who a bulk send reaches, what it costs, who it leaves out and what it says.
     *
     * @param  Collection<int, covariant Model>  $records
     */
    private static function describeBulkSend(Collection $records): string
    {
        $text = SmsText::forBroadcast(SendSignUpConfirmation::BODY);
        $recipients = $records->loadMissing('signUpConfirmation')->filter(fn (Model $subscriber): bool => $subscriber instanceof Subscriber && $subscriber->canBeSentConfirmation())->count();
        $leftOut = $records->count() - $recipients;

        return implode(' ', array_filter([
            (new BroadcastPreview($text, $recipients))->confirmation(),
            $leftOut === 0 ? null : Number::format($leftOut).' of those selected '.($leftOut === 1 ? 'is' : 'are').' left out, because they’ve opted out or already have one.',
            'They’ll get this text'.(QuietHours::contains(now()) ? ' after 8am' : '').': '.$text->text,
        ], fn (?string $sentence): bool => $sentence !== null));
    }

    /**
     * @return list<string|null>
     */
    private static function csvRow(Subscriber $subscriber): array
    {
        $timezone = config()->string('shop.timezone');

        return [
            SpreadsheetCell::safe($subscriber->first_name),
            $subscriber->phone,
            $subscriber->postcode,
            $subscriber->isSubscribed() ? 'yes' : 'no',
            $subscriber->consented_at->setTimezone($timezone)->format('Y-m-d H:i:s T'),
            $subscriber->consent_source,
            $subscriber->consent_wording,
            $subscriber->consent_ip,
            $subscriber->unsubscribed_at?->setTimezone($timezone)->format('Y-m-d H:i:s T'),
        ];
    }
}
