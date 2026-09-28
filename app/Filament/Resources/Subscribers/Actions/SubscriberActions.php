<?php

namespace App\Filament\Resources\Subscribers\Actions;

use App\Models\Subscriber;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
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
            ->modalDescription('They won’t get any more broadcasts. To start again, they need to sign up on the website, which records their consent again.')
            ->modalSubmitActionLabel('Opt them out')
            ->action(function (Subscriber $record): void {
                $record->optOut();

                Notification::make()->title('Opted out')->success()->send();
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

                Subscriber::query()->orderBy('id')->lazy()->each(fn (Subscriber $subscriber) => fputcsv($file, self::csvRow($subscriber), escape: ''));

                fclose($file);
            }, 'subscribers-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']));
    }

    /**
     * @return list<string|null>
     */
    private static function csvRow(Subscriber $subscriber): array
    {
        $timezone = config()->string('shop.timezone');

        return [
            self::withoutFormula($subscriber->first_name),
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

    /**
     * Spreadsheet apps run cells that start with =, +, - or @ as formulas, so names people typed are quoted.
     */
    private static function withoutFormula(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
