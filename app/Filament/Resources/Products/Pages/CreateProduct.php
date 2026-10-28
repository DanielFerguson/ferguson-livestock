<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Products\SetProductBundle;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

/**
 * @property Product $record
 */
class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        $componentId = $this->data['bundle_component_id'] ?? null;
        $units = $this->data['bundle_units'] ?? null;

        if (is_numeric($componentId)) {
            app(SetProductBundle::class)($this->record, Product::query()->findOrFail((int) $componentId), is_numeric($units) ? (int) $units : 1);
        }
    }
}
