<?php

namespace App\Filament\Resources\SmsBroadcasts;

use App\Filament\Resources\SmsBroadcasts\Pages\CreateSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\Pages\EditSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\Pages\ListSmsBroadcasts;
use App\Filament\Resources\SmsBroadcasts\Pages\ViewSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\SmsBroadcasts\Schemas\SmsBroadcastForm;
use App\Filament\Resources\SmsBroadcasts\Schemas\SmsBroadcastInfolist;
use App\Filament\Resources\SmsBroadcasts\Tables\SmsBroadcastsTable;
use App\Models\SmsBroadcast;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Texts to the wait list. A broadcast is written and checked here, then sent or scheduled from its page;
 * once sending starts it can't be changed.
 */
class SmsBroadcastResource extends Resource
{
    protected static ?string $model = SmsBroadcast::class;

    protected static ?string $modelLabel = 'broadcast';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Texts';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return SmsBroadcastForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmsBroadcastInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsBroadcastsTable::configure($table);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof SmsBroadcast && $record->status()->isEditable();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record);
    }

    public static function getRelations(): array
    {
        return [
            RecipientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsBroadcasts::route('/'),
            'create' => CreateSmsBroadcast::route('/create'),
            'view' => ViewSmsBroadcast::route('/{record}'),
            'edit' => EditSmsBroadcast::route('/{record}/edit'),
        ];
    }
}
