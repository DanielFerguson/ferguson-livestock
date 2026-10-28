<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('bundle.component'))
            ->filters([
                TernaryFilter::make('bundle')
                    ->label('Bundles')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('bundle'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('bundle'),
                    ),
            ])
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('bundle')
                    ->label('Bundle of')
                    ->state(fn (Product $record): ?string => $record->bundle === null ? null : "{$record->bundle->units} × {$record->bundle->component->name}")
                    ->placeholder('Not a bundle'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
