<?php

namespace App\Filament\Resources\SmsReplies\Tables;

use App\Models\SmsMessage;
use App\Sms\OptOutKeywords;
use App\Support\AustralianMobile;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SmsRepliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subscriber'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime('D j M, g:ia', config()->string('shop.timezone')),
                TextColumn::make('sender')
                    ->label('From')
                    ->state(fn (SmsMessage $record): string => $record->subscriber->first_name ?? AustralianMobile::format($record->from))
                    ->description(fn (SmsMessage $record): ?string => $record->subscriber?->first_name !== null ? AustralianMobile::format($record->from) : null),
                TextColumn::make('body')
                    ->label('Message')
                    ->wrap()
                    ->searchable()
                    ->description(fn (SmsMessage $record): ?string => OptOutKeywords::matches($record->body) ? 'Asked to stop, so they were opted out.' : null),
                TextColumn::make('read_at')
                    ->label('')
                    ->state(fn (SmsMessage $record): string => $record->read_at === null ? 'New' : 'Read')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'New' ? 'warning' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('unread')
                    ->label('Unread')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('read_at'),
                        false: fn (Builder $query) => $query->whereNotNull('read_at'),
                    ),
            ])
            ->recordActions([
                Action::make('markRead')
                    ->label('Mark read')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (SmsMessage $record): bool => $record->read_at === null)
                    ->action(fn (SmsMessage $record) => $record->update(['read_at' => now()])),
            ])
            ->toolbarActions([
                BulkAction::make('markRead')
                    ->label('Mark read')
                    ->icon(Heroicon::OutlinedCheck)
                    ->action(fn (Collection $records) => SmsMessage::query()->whereKey($records->modelKeys())->whereNull('read_at')->update(['read_at' => now()])),
            ]);
    }
}
