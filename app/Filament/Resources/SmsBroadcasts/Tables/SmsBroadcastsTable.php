<?php

namespace App\Filament\Resources\SmsBroadcasts\Tables;

use App\Models\SmsBroadcast;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SmsBroadcastsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('messages'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('body')->label('Message')->limit(70)->wrap()->searchable(),
                TextColumn::make('status')
                    ->state(fn (SmsBroadcast $record) => $record->status())
                    ->badge(),
                TextColumn::make('audience')
                    ->label('To')
                    ->state(fn (SmsBroadcast $record): string => $record->postcodes() === [] ? 'Everyone' : implode(', ', $record->postcodes())),
                TextColumn::make('when')
                    ->state(fn (SmsBroadcast $record) => $record->started_at ?? $record->scheduled_for)
                    ->dateTime('D j M Y, g:ia', config()->string('shop.timezone'))
                    ->placeholder('Not scheduled'),
                TextColumn::make('messages_count')->label('Texts')->numeric(),
            ]);
    }
}
