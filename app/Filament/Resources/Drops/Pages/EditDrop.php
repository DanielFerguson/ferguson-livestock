<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drops\Pages;

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
            DropActions::announce(),
            DropActions::duplicate(),
            DropActions::closeNow(),
            DeleteAction::make()
                ->visible(fn (Drop $record): bool => $record->whyItCantBeDeleted() === null),
        ];
    }

    /**
     * Refuse to remove a product customers are holding, have bought, or ever ordered: orders keep their lines.
     */
    protected function beforeSave(): void
    {
        /** @var array<string, mixed> $items */
        $items = $this->data['items'] ?? [];

        $kept = collect(array_keys($items))
            ->filter(fn (string $key): bool => str_starts_with($key, 'record-'))
            ->map(fn (string $key): int => (int) substr($key, strlen('record-')));

        $removed = $this->record->items()->with('product')->get()->reject(fn (DropItem $item): bool => $kept->contains($item->id));

        $blocked = $removed->first(fn (DropItem $item): bool => $item->whyItCantBeRemoved() !== null);

        if ($blocked !== null) {
            Notification::make()
                ->title("{$blocked->product->name} can’t be removed")
                ->body($blocked->whyItCantBeRemoved())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
