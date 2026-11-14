<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\CreateCashOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\PrintDeliveryRun;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\CashOrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders are placed by customers and moved on by Stripe, so they're only viewed and fulfilled here. The one exception
 * is a cash order for family or friends, which the owner records by hand.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 0;

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Order ? $record->reference() : 'Order';
    }

    /**
     * Orders still to pack and deliver: paid ones, and cash ones put aside for friends.
     */
    public static function getNavigationBadge(): ?string
    {
        $toFulfil = Order::query()->whereIn('status', OrderStatus::toFulfil())->count();

        return $toFulfil > 0 ? (string) $toFulfil : null;
    }

    /**
     * @return Builder<Model>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->with(['drop', 'items.product']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function form(Schema $schema): Schema
    {
        return CashOrderForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateCashOrder::route('/create'),
            'delivery-run' => PrintDeliveryRun::route('/delivery-run/{drop}'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
