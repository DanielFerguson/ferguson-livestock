<?php

namespace App\Filament\Resources\Drops\Pages;

use App\Enums\DropStatus;
use App\Filament\Resources\Drops\Actions\DropActions;
use App\Filament\Resources\Drops\DropResource;
use App\Models\Drop;
use App\Models\DropItem;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Drop $record
 */
class EditDrop extends EditRecord
{
    protected static string $resource = DropResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DropActions::runPreflight(),
            DropActions::duplicate(),
            DropActions::closeNow(),
            DeleteAction::make()
                ->visible(fn (Drop $record): bool => in_array($record->status(), [DropStatus::Draft, DropStatus::Scheduled], true)
                    && $record->items->every(fn (DropItem $item): bool => $item->committed() === 0)),
        ];
    }

    /**
     * Refuse to remove a product customers are holding or have bought.
     */
    protected function beforeSave(): void
    {
        /** @var array<string, mixed> $items */
        $items = $this->data['items'] ?? [];

        $kept = collect(array_keys($items))
            ->filter(fn (string $key): bool => str_starts_with($key, 'record-'))
            ->map(fn (string $key): int => (int) substr($key, strlen('record-')));

        $removed = $this->record->items()->with('product')->get()->reject(fn (DropItem $item): bool => $kept->contains($item->id));

        $blocked = $removed->first(fn (DropItem $item): bool => $item->committed() > 0);

        if ($blocked !== null) {
            Notification::make()
                ->title("{$blocked->product->name} can’t be removed")
                ->body("{$blocked->committed()} units are held or sold. Set its stock to that number instead.")
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
