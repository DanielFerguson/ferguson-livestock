<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\DeliveryMethod;
use App\Enums\ProductType;
use App\Models\Drop;
use App\Models\DropItem;
use App\Stock\DropSales;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * The form for a cash order put aside for family or friends. It only gathers the order: RecordCashOrder checks it,
 * prices it at the drop's prices and holds the stock.
 */
class CashOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Order')
                    ->description('For family and friends paying cash in person, never customers. It takes stock from the drop like a website order, at the drop’s prices.')
                    ->columns(2)
                    ->schema([
                        Select::make('drop_id')
                            ->label('Drop')
                            ->options(fn (): array => self::dropOptions())
                            ->default(fn (): ?int => self::defaultDropId())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('items', []);
                                $set('delivery_day', null);
                            })
                            ->columnSpanFull(),
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->columns(3)
                            ->columnSpanFull()
                            ->addActionLabel('Add an item')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->required()
                            ->schema([
                                Select::make('drop_item_id')
                                    ->label('Item')
                                    ->options(fn (Get $get): array => self::itemOptions($get('../../drop_id')))
                                    ->required()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->live()
                                    ->columnSpan(2),
                                TextInput::make('quantity')
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true),
                            ]),
                        ToggleButtons::make('delivery_method')
                            ->label('Pickup or delivery')
                            ->options(DeliveryMethod::class)
                            ->default(DeliveryMethod::Pickup->value)
                            ->inline()
                            ->required()
                            ->live(),
                        Select::make('delivery_day')
                            ->label('Delivery day')
                            ->options(fn (Get $get): array => self::deliveryDayOptions($get('drop_id')))
                            ->required(fn (Get $get): bool => self::isDelivery($get))
                            ->visible(fn (Get $get): bool => self::isDelivery($get)),
                        Toggle::make('paid')
                            ->label('Cash already paid')
                            ->helperText('Leave off if they’ll pay at pickup or on delivery. The order shows as Cash owed until you mark it paid.')
                            ->default(false)
                            ->columnSpanFull(),
                        TextEntry::make('total')
                            ->label('Total')
                            ->state(fn (Get $get): string => self::total($get))
                            ->columnSpanFull(),
                    ]),
                Section::make('Who it’s for')
                    ->columns(2)
                    ->schema([
                        TextInput::make('customer_name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Mobile')
                            ->tel()
                            ->maxLength(32)
                            ->required(fn (Get $get): bool => self::isDelivery($get)),
                        TextInput::make('street')
                            ->label('Street address')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => self::isDelivery($get))
                            ->visible(fn (Get $get): bool => self::isDelivery($get))
                            ->columnSpanFull(),
                        TextInput::make('suburb')
                            ->maxLength(100)
                            ->required(fn (Get $get): bool => self::isDelivery($get))
                            ->visible(fn (Get $get): bool => self::isDelivery($get)),
                        TextInput::make('postcode')
                            ->maxLength(4)
                            ->required(fn (Get $get): bool => self::isDelivery($get))
                            ->visible(fn (Get $get): bool => self::isDelivery($get)),
                    ]),
            ]);
    }

    public static function isDelivery(Get $get): bool
    {
        $method = $get('delivery_method');

        return $method === DeliveryMethod::Delivery || $method === DeliveryMethod::Delivery->value;
    }

    /**
     * Drops that can still take orders, newest first.
     *
     * @return array<int, string>
     */
    private static function dropOptions(): array
    {
        return once(fn (): array => Drop::query()->with('items')->latest('opens_at')->limit(12)->get()
            ->reject(fn (Drop $drop): bool => $drop->hasEnded())
            ->mapWithKeys(fn (Drop $drop): array => [$drop->id => $drop->name])
            ->all());
    }

    private static function defaultDropId(): ?int
    {
        $featured = Drop::featured()?->id;

        return $featured !== null && array_key_exists($featured, self::dropOptions()) ? $featured : array_key_first(self::dropOptions());
    }

    private static function drop(mixed $dropId): ?Drop
    {
        if (! is_numeric($dropId)) {
            return null;
        }

        return once(fn (): ?Drop => Drop::query()->with('items.product.bundle')->find((int) $dropId));
    }

    /**
     * What the drop sells, labelled with its price and what's left: "5kg Beef Box · $165 · 41 left".
     *
     * @return array<int, string>
     */
    private static function itemOptions(mixed $dropId): array
    {
        $drop = self::drop($dropId);

        if (! $drop instanceof Drop) {
            return [];
        }

        return $drop->items
            ->reject(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery)
            ->sortBy(fn (DropItem $item): int => $item->product->sort)
            ->mapWithKeys(fn (DropItem $item): array => [$item->id => implode(' · ', [$item->product->name, Money::format($item->price), DropSales::left($drop, $item).' left'])])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function deliveryDayOptions(mixed $dropId): array
    {
        $drop = self::drop($dropId);

        return collect($drop->delivery_days ?? [])
            ->mapWithKeys(fn (string $day): array => [$day => CarbonImmutable::parse($day)->format('l j F')])
            ->all();
    }

    /**
     * "$187", the drop's prices for what's chosen, plus the delivery fee for a delivery.
     */
    private static function total(Get $get): string
    {
        $drop = self::drop($get('drop_id'));

        if (! $drop instanceof Drop) {
            return Money::format(0);
        }

        $total = 0;

        foreach ((array) $get('items') as $line) {
            $item = is_array($line) && is_numeric($line['drop_item_id'] ?? null) ? $drop->items->firstWhere('id', (int) $line['drop_item_id']) : null;
            $quantity = is_array($line) && is_numeric($line['quantity'] ?? null) ? max(0, (int) $line['quantity']) : 0;

            $total += $item instanceof DropItem ? $item->price * $quantity : 0;
        }

        if (self::isDelivery($get)) {
            $total += $drop->items->first(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery)->price ?? 0;
        }

        return Money::format($total);
    }
}
