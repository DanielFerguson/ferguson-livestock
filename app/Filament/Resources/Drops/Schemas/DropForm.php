<?php

namespace App\Filament\Resources\Drops\Schemas;

use App\Enums\ProductType;
use App\Exceptions\QuantityBelowCommitted;
use App\Filament\Forms\LocalDateTimePicker;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;
use App\Payments\PaymentGateway;
use App\Payments\PriceCheck;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Throwable;

class DropForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('When and where')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('October drop')
                            ->columnSpanFull(),
                        LocalDateTimePicker::make('opens_at')
                            ->label('Orders open')
                            ->required()
                            ->rule(fn (Get $get, ?Drop $record): Closure => self::noClashingDrop($get, $record)),
                        LocalDateTimePicker::make('closes_at')
                            ->label('Orders close (optional)')
                            ->after('opens_at')
                            ->helperText('Leave empty to stay open until it sells out, you close it, or the next drop opens.'),
                        Toggle::make('published_at')
                            ->label('Published')
                            ->helperText('Drafts are hidden from customers. A published drop goes live at its opening time, and closes the previous drop.')
                            ->live()
                            // A toggle's default state is false, which filled() counts as filled: without the check a draft opens as published.
                            ->formatStateUsing(fn (mixed $state): bool => $state !== false && filled($state))
                            ->dehydrateStateUsing(fn (bool $state, ?Drop $record): ?CarbonImmutable => $state ? ($record->published_at ?? now()) : null)
                            ->columnSpanFull(),
                        Toggle::make('announced_at')
                            ->label('Announce the date on the website')
                            ->helperText('Shows “Next drop: {date}” with a link to the wait list. The time, products and prices stay hidden until you publish.')
                            ->visible(fn (Get $get): bool => ! $get('published_at'))
                            ->formatStateUsing(fn (mixed $state): bool => $state !== false && filled($state))
                            ->dehydrateStateUsing(fn (bool $state, ?Drop $record): ?CarbonImmutable => $state ? ($record?->announced_at ?? now()) : null)
                            ->columnSpanFull(),
                        Repeater::make('delivery_days')
                            ->label('Delivery days')
                            ->simple(DatePicker::make('day')->required()->native())
                            ->addActionLabel('Add a delivery day')
                            ->helperText('Customers choosing delivery pick one of these days.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Products')
                    ->description('Prices come from Stripe: paste each product’s price ID and the amount fills in.')
                    ->schema([self::items()]),
                Section::make('Pre-flight check')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('preflight_report')
                            ->hiddenLabel()
                            ->state(fn (?Drop $record): string => self::describePreflight($record)),
                    ]),
            ]);
    }

    private static function items(): Repeater
    {
        return Repeater::make('items')
            ->hiddenLabel()
            ->relationship()
            ->columns(6)
            ->addActionLabel('Add a product')
            ->defaultItems(0)
            ->itemLabel(fn (array $state): ?string => self::product($state['product_id'] ?? null)?->name)
            ->schema([
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn (): array => Product::orderBy('sort')->pluck('name', 'id')->all())
                    ->required()
                    ->distinct()
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                    ->live()
                    // Customers are holding or have bought this item, so it can't become a different product.
                    ->disabled(fn (?DropItem $record): bool => ($record?->committed() ?? 0) > 0)
                    ->columnSpan(2),
                TextInput::make('stripe_price_id')
                    ->label('Stripe price ID')
                    ->placeholder('price_…')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Set $set) => self::fillPriceFromStripe($state, $set))
                    ->rule(fn (Get $get): Closure => self::stripePriceMatches($get))
                    ->columnSpan(2),
                TextInput::make('price')
                    ->label('Price')
                    ->prefix('$')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.01')
                    ->required()
                    ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state) ? self::dollars((int) $state) : null)
                    ->dehydrateStateUsing(fn (mixed $state): int => is_numeric($state) ? (int) round(((float) $state) * 100) : 0),
                TextInput::make('max_per_order')
                    ->label('Limit each')
                    ->integer()
                    ->minValue(1)
                    ->default(10)
                    ->required()
                    ->visible(fn (Get $get): bool => self::product($get('product_id'))?->type !== ProductType::Delivery),
                TextInput::make('quantity')
                    ->label('Stock')
                    ->integer()
                    ->required()
                    ->minValue(fn (?DropItem $record): int => max(1, $record?->committed() ?? 0))
                    ->validationMessages(['min' => 'At least :min — that many are already held or sold.'])
                    ->visible(fn (Get $get): bool => self::product($get('product_id'))?->hasOwnStock() ?? false),
                TextEntry::make('availability')
                    ->label('Left')
                    ->state(fn (?DropItem $record): ?string => $record?->hasOwnStock()
                        ? "{$record->available} of {$record->quantity} ({$record->committed()} held or sold)"
                        : null)
                    ->visible(fn (?DropItem $record): bool => $record?->hasOwnStock() ?? false)
                    ->columnSpan(2),
                TextEntry::make('shared_stock')
                    ->label('Stock')
                    ->state(fn (Get $get): ?string => self::sharedStockNote(self::product($get('product_id'))))
                    ->visible(fn (Get $get): bool => self::product($get('product_id'))?->stock_product_id !== null)
                    ->columnSpan(3),
            ])
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, DropItem $record): array => self::applyQuantityChange($data, $record));
    }

    /**
     * Quantity changes move `available` by the same amount in one conditional update, so units customers
     * are holding while the admin edits stay held. `available` itself is never written from the form.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function applyQuantityChange(array $data, DropItem $record): array
    {
        $product = self::product($data['product_id'] ?? $record->product_id);

        if (! ($product?->hasOwnStock() ?? false)) {
            return [...$data, 'quantity' => null, 'available' => null];
        }

        if (! $record->hasOwnStock()) {
            return [...$data, 'available' => $data['quantity']];
        }

        try {
            $record->adjustQuantityTo(is_numeric($data['quantity'] ?? null) ? (int) $data['quantity'] : 0);
        } catch (QuantityBelowCommitted $exception) {
            Notification::make()->title("{$product->name}: {$exception->getMessage()}")->danger()->send();

            throw new Halt;
        }

        unset($data['quantity'], $data['available']);

        return $data;
    }

    private static function fillPriceFromStripe(?string $priceId, Set $set): void
    {
        if (blank($priceId)) {
            return;
        }

        try {
            $price = app(PaymentGateway::class)->retrievePrice(trim($priceId));
        } catch (Throwable) {
            return;
        }

        if ($price?->unitAmount !== null) {
            $set('price', self::dollars($price->unitAmount));
        }
    }

    private static function stripePriceMatches(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! is_string($value) || $value === '' || ! is_numeric($get('price'))) {
                return;
            }

            try {
                $price = app(PaymentGateway::class)->retrievePrice(trim($value));
            } catch (Throwable) {
                $fail('Couldn’t reach Stripe to check this price. Try again in a minute.');

                return;
            }

            foreach (PriceCheck::problems($price, (int) round(((float) $get('price')) * 100)) as $problem) {
                $fail($problem);
            }
        };
    }

    private static function noClashingDrop(Get $get, ?Drop $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            if (! $get('published_at') || ! is_string($value) || $value === '') {
                return;
            }

            $timezone = config()->string('shop.timezone');
            $closesAt = $get('closes_at');

            $clash = Drop::conflictingWith(
                CarbonImmutable::parse($value, $timezone)->utc(),
                is_string($closesAt) && $closesAt !== '' ? CarbonImmutable::parse($closesAt, $timezone)->utc() : null,
                $record?->id,
            );

            if ($clash !== null) {
                $opens = $clash->opens_at->setTimezone($timezone)->format('g:ia D j M');
                $fail("This overlaps “{$clash->name}”, which opens at {$opens}. Only one drop can be open at a time.");
            }
        };
    }

    private static function describePreflight(?Drop $record): string
    {
        $report = $record?->preflight_report;

        if ($report === null) {
            return 'Not run yet. It runs automatically 10 minutes before the drop opens, or use “Run pre-flight check”.';
        }

        $checked = CarbonImmutable::parse($report['checked_at'])->setTimezone(config()->string('shop.timezone'))->format('g:ia D j M');

        return $report['passed']
            ? "Passed at {$checked}."
            : "Found problems at {$checked}: ".implode(' ', $report['problems']);
    }

    /**
     * Boxes like the 10kg box have no stock of their own: each one sold takes units from another box's stock.
     */
    private static function sharedStockNote(?Product $product): ?string
    {
        if ($product?->stock_product_id === null) {
            return null;
        }

        $stockProduct = self::product($product->stock_product_id);

        return $stockProduct === null ? null : "Packed from the {$stockProduct->name} stock, {$product->stock_units} per box.";
    }

    private static function product(mixed $id): ?Product
    {
        return is_numeric($id) ? self::findProduct((int) $id) : null;
    }

    /**
     * Memoised for the request: the form asks about the same few products many times while rendering.
     */
    private static function findProduct(int $id): ?Product
    {
        return once(fn (): ?Product => Product::find($id));
    }

    private static function dollars(int $cents): string
    {
        return $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, '.', '');
    }
}
