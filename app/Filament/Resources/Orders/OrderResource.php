<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\PrintDeliveryRun;
use App\Filament\Resources\Orders\Pages\ViewOrder;
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
 * Orders are placed by customers and moved on by Stripe, so they're only viewed and fulfilled here.
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
     * Paid orders still to pack and deliver.
     */
    public static function getNavigationBadge(): ?string
    {
        $toFulfil = Order::query()->where('status', OrderStatus::Paid)->count();

        return $toFulfil > 0 ? (string) $toFulfil : null;
    }

    /**
     * @return Builder<Order>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return Order::query()->with(['drop', 'items.product']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'delivery-run' => PrintDeliveryRun::route('/delivery-run/{drop}'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
