<?php

namespace App\Filament\Resources\Drops\Actions;

use App\Actions\RunDropPreflight;
use App\Filament\Resources\Drops\DropResource;
use App\Models\Drop;
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
