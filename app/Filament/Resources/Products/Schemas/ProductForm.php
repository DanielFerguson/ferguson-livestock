<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductType;
use App\Models\Bundle;
use App\Models\Product;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    /**
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private static function bundleRules(?Product $record, Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
            $component = is_numeric($value) ? Product::query()->find((int) $value) : null;

            if (! $component instanceof Product) {
                return;
            }

            $units = is_numeric($get('bundle_units')) ? (int) $get('bundle_units') : 1;
            $type = $get('type');

            foreach (Bundle::problemsFor($record ?? new Product(['type' => $type]), $component, $units) as $problem) {
                $fail($problem);
            }
        };
    }

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
                    ]),
                Section::make('Bundle')
                    ->description('A bundle is sold as several of another product, like the 10kg box (two 5kg boxes) or a 5kg mince pack (ten 500g packs). It uses that product’s stock, so they can never be oversold together. Leave empty for a product with stock of its own.')
                    ->visible(fn (Get $get): bool => $get('type') !== ProductType::Delivery && $get('type') !== ProductType::Delivery->value)
                    ->columns(2)
                    ->schema([
                        Select::make('bundle_component_id')
                            ->label('Bundle of')
                            ->placeholder('Not a bundle')
                            ->options(fn (?Product $record): array => Product::query()->where('type', '!=', ProductType::Delivery)
                                ->when($record?->id, fn ($query, int $id) => $query->whereKeyNot($id))
                                ->whereDoesntHave('bundle')
                                ->orderBy('sort')
                                ->pluck('name', 'id')
                                ->all())
                            ->afterStateHydrated(fn (Select $component, ?Product $record): Select => $component->state($record->bundle->component_product_id ?? null))
                            ->dehydrated(false)
                            ->live()
                            ->rule(fn (?Product $record, Get $get): Closure => self::bundleRules($record, $get)),
                        TextInput::make('bundle_units')
                            ->label('How many each one uses')
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->afterStateHydrated(fn (TextInput $component, ?Product $record): TextInput => $component->state($record->bundle->units ?? 1))
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => filled($get('bundle_component_id'))),
                    ]),
            ]);
    }
}
