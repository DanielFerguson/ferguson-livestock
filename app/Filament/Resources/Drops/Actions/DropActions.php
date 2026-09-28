<?php

namespace App\Filament\Resources\Drops\Actions;

use App\Actions\RunDropPreflight;
use App\Filament\Resources\Drops\DropResource;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use App\Models\Drop;
use App\Models\SmsBroadcast;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The drop actions shared by the table and the edit page.
 */
final class DropActions
{
    public static function closeNow(): Action
    {
        return Action::make('closeNow')
            ->label('Close now')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->visible(fn (Drop $record): bool => $record->status()->isOpen())
            ->requiresConfirmation()
            ->modalHeading('Close this drop now?')
            ->modalDescription('Customers can’t start new orders. Anyone already at the payment page can still finish paying.')
            ->modalSubmitActionLabel('Close the drop')
            ->action(function (Drop $record): void {
                $record->closeNow();

                Notification::make()->title('The drop is closed')->success()->send();
            });
    }

    public static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->modalHeading('Duplicate this drop?')
            ->modalDescription('Makes a draft a week later with the same products, prices and delivery fee. Check the times and stock before publishing it.')
            ->modalSubmitActionLabel('Duplicate')
            ->action(function (Drop $record): void {
                $copy = $record->duplicateAsDraft();

                Notification::make()->title('Draft copy created')->success()->send();

                redirect(DropResource::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * Draft a text to the wait list with the drop's opening time and the order link, ready to check and schedule.
     */
    public static function announce(): Action
    {
        return Action::make('announce')
            ->label('Announce by text')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('gray')
            ->action(function (Drop $record): void {
                $broadcast = SmsBroadcast::create([
                    'body' => self::announcement($record),
                    'drop_id' => $record->id,
                    'created_by' => auth()->id(),
                ]);

                Notification::make()
                    ->title('Announcement drafted')
                    ->body('Check the wording, send yourself a test, then schedule it for when the drop opens.')
                    ->success()
                    ->send();

                redirect(SmsBroadcastResource::getUrl('edit', ['record' => $broadcast]));
            });
    }

    public static function runPreflight(): Action
    {
        return Action::make('runPreflight')
            ->label('Run pre-flight check')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->action(function (Drop $record): void {
                $report = app(RunDropPreflight::class)($record);

                $notification = Notification::make()->persistent();

                if ($report['passed']) {
                    $notification->title('Ready to open')->body('Every price, the stock, delivery and the Stripe webhook check out.')->success();
                } else {
                    $notification->title('Fix before opening')->body(implode("\n", $report['problems']))->danger();
                }

                $notification->send();
            });
    }

    /**
     * "Our next beef drop opens Saturday 10 October at 9am. Order at fergusonlivestock.com.au/order"
     */
    private static function announcement(Drop $drop): string
    {
        $opensAt = $drop->opens_at->setTimezone(config()->string('shop.timezone'));
        $time = $opensAt->minute === 0 ? $opensAt->format('ga') : $opensAt->format('g:ia');
        $site = preg_replace('#^https?://(www\.)?#', '', rtrim(config()->string('shop.url'), '/'));

        return "Our next beef drop opens {$opensAt->format('l j F')} at {$time}. Order at {$site}".route('order', absolute: false);
    }
}
