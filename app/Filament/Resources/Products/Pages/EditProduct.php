<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Drops keep a record of what they sold, so a product that has been in one stays.
            DeleteAction::make()->visible(fn (Product $record): bool => ! $record->dropItems()->exists()),
        ];
    }
}
