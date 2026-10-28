<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Products\SetProductBundle;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Product $record
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterSave(): void
    {
        $componentId = $this->data['bundle_component_id'] ?? null;
        $units = $this->data['bundle_units'] ?? null;

        app(SetProductBundle::class)(
            $this->record,
            is_numeric($componentId) ? Product::query()->findOrFail((int) $componentId) : null,
            is_numeric($units) ? (int) $units : null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            // Drops keep a record of what they sold, so a product that has been in one stays.
            DeleteAction::make()->visible(fn (Product $record): bool => ! $record->dropItems()->exists()),
        ];
    }
}
