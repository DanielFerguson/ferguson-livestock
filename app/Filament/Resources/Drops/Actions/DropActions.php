<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drops\Actions;

use App\Actions\Drops\DisableEarlyAccess;
use App\Actions\Drops\EnableEarlyAccess;
use App\Actions\RunDropPreflight;
use App\Exceptions\CannotChangeDrop;
use App\Filament\Resources\Drops\DropResource;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use App\Models\Drop;
use App\Models\SmsBroadcast;
use App\Sms\DropAnnouncement;
use Closure;
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

    /**
     * Turns on the secret link that lets family and friends order before the drop is public.
     */
    public static function startEarlyAccess(): Action
    {
        return Action::make('startEarlyAccess')
            ->label('Turn on early access')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn (Drop $record): bool => ! $record->acceptsEarlyOrders() && ! $record->hasEnded())
            ->requiresConfirmation()
            ->modalHeading('Turn on early access?')
            ->modalDescription('Anyone with the link can order from this drop’s stock before it is public. Keep the drop unpublished until you want everyone to see it.')
            ->modalSubmitActionLabel('Turn on')
            ->action(fn (Drop $record) => self::changeEarlyAccess($record, fn () => app(EnableEarlyAccess::class)($record), 'Early access is on. Copy the link below.'));
    }

    public static function replaceEarlyAccessLink(): Action
    {
        return Action::make('replaceEarlyAccessLink')
            ->label('New early access link')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Drop $record): bool => $record->acceptsEarlyOrders())
            ->requiresConfirmation()
            ->modalHeading('Replace the early access link?')
            ->modalDescription('The current link stops working straight away. Orders already placed stand.')
            ->modalSubmitActionLabel('Replace the link')
            ->action(fn (Drop $record) => self::changeEarlyAccess($record, fn () => app(EnableEarlyAccess::class)($record, newLink: true), 'New link made. Copy it below.'));
    }

    public static function stopEarlyAccess(): Action
    {
        return Action::make('stopEarlyAccess')
            ->label('Turn off early access')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->visible(fn (Drop $record): bool => $record->early_access_token !== null)
            ->requiresConfirmation()
            ->modalHeading('Turn off early access?')
            ->modalDescription('The link stops working. Orders already placed stand.')
            ->modalSubmitActionLabel('Turn off')
            ->action(fn (Drop $record) => self::changeEarlyAccess($record, fn () => app(DisableEarlyAccess::class)($record), 'Early access is off.'));
    }

    /**
     * @param  Closure(): mixed  $change
     */
    private static function changeEarlyAccess(Drop $record, Closure $change, string $done): void
    {
        try {
            $change();
        } catch (CannotChangeDrop $exception) {
            Notification::make()->title('Early access not changed')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();

        redirect(DropResource::getUrl('edit', ['record' => $record]));
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
                $broadcast = SmsBroadcast::query()->create([
                    'body' => DropAnnouncement::bodyFor($record),
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
}
