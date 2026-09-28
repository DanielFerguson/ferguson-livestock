<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductType;
use App\Models\Product;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $isBox = fn (Get $get): bool => $get('type') === ProductType::Box || $get('type') === ProductType::Box->value;

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(100),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit')
                            ->helperText('The site’s code refers to products by this, so it can’t change once created.'),
                        Select::make('type')
                            ->options(ProductType::class)
                            ->required()
                            ->live()
                            ->disabledOn('edit'),
                        TextInput::make('sort')
                            ->label('Order on the site')
                            ->integer()
                            ->default(0),
                        Textarea::make('description')->required()->rows(2)->columnSpanFull(),
                    ]),
                Section::make('Box details')
                    ->visible($isBox)
                    ->columns(2)
                    ->schema([
                        TextInput::make('box_details.weight_kg')->label('Weight (kg)')->integer()->minValue(1)->required(),
                        TextInput::make('box_details.best_for')->label('Best for')->required(),
                        TextInput::make('box_details.freezer_guidance')->label('Freezer guidance')->required()->columnSpanFull(),
                        Repeater::make('box_details.contents')
                            ->label('What’s in it')
                            ->simple(TextInput::make('line')->required())
                            ->addActionLabel('Add a line')
                            ->columnSpanFull(),
                        Select::make('stock_product_id')
                            ->label('Uses stock from')
                            ->helperText('For a bigger box packed from the same stock, e.g. the 10kg box uses 5kg-box stock.')
                            ->options(fn (?Product $record): array => Product::where('type', ProductType::Box)
                                ->when($record?->id, fn ($query, int $id) => $query->whereKeyNot($id))
                                ->whereNull('stock_product_id')
                                ->pluck('name', 'id')
                                ->all())
                            ->live(),
                        TextInput::make('stock_units')
                            ->label('Units of that stock each one uses')
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->visible(fn (Get $get): bool => filled($get('stock_product_id'))),
                    ]),
            ]);
    }
}
