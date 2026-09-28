<?php

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Enums\SmsDirection;
use App\Models\SmsMessage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every text sent to and received from this person.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Texts';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M Y, g:ia', config()->string('shop.timezone')),
                TextColumn::make('direction')
                    ->label('')
                    ->state(fn (SmsMessage $record): string => $record->direction === SmsDirection::Inbound ? 'Reply' : 'Sent to them')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Reply' ? 'warning' : 'gray'),
                TextColumn::make('body')->wrap(),
                TextColumn::make('status')->badge(),
            ]);
    }
}
