<?php

namespace App\Filament\Resources\SmsReplies;

use App\Enums\SmsDirection;
use App\Filament\Resources\SmsReplies\Pages\ListSmsReplies;
use App\Filament\Resources\SmsReplies\Tables\SmsRepliesTable;
use App\Models\SmsMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Texts people send back to the shop's number, newest first.
 */
class SmsReplyResource extends Resource
{
    protected static ?string $model = SmsMessage::class;

    protected static ?string $modelLabel = 'reply';

    protected static ?string $slug = 'replies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Texts';

    protected static ?int $navigationSort = 2;

    /**
     * @return Builder<SmsMessage>
     */
    public static function getEloquentQuery(): Builder
    {
        return SmsMessage::query()->where('direction', SmsDirection::Inbound);
    }

    public static function getNavigationBadge(): ?string
    {
        $unread = self::unreadCount();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function unreadCount(): int
    {
        return self::getEloquentQuery()->whereNull('read_at')->count();
    }

    public static function table(Table $table): Table
    {
        return SmsRepliesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsReplies::route('/'),
        ];
    }
}
