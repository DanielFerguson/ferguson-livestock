<?php

namespace App\Filament\Resources\Subscribers\Tables;

use App\Filament\Resources\Subscribers\Actions\SubscriberActions;
use App\Models\Subscriber;
use App\Support\AustralianMobile;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscribersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('consented_at', 'desc')
            ->columns([
                TextColumn::make('first_name')->label('Name')->placeholder('—')->searchable(),
                TextColumn::make('phone')
                    ->label('Mobile')
                    ->formatStateUsing(fn (string $state): string => AustralianMobile::format($state))
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchPhone($query, $search)),
                TextColumn::make('postcode')->placeholder('—')->searchable()->sortable(),
                TextColumn::make('consented_at')->label('Joined')->date('j M Y', config()->string('shop.timezone'))->sortable(),
                TextColumn::make('consent_source')->label('From')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->state(fn (Subscriber $record): string => $record->isSubscribed() ? 'Subscribed' : 'Opted out')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Subscribed' ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('opted_out')
                    ->label('Opted out')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('unsubscribed_at'),
                        false: fn (Builder $query) => $query->whereNull('unsubscribed_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                SubscriberActions::optOut(),
            ]);
    }

    /**
     * @param  Builder<Subscriber>  $query
     * @return Builder<Subscriber>
     */
    private static function searchPhone(Builder $query, string $search): Builder
    {
        $digits = AustralianMobile::searchDigits($search);

        return $digits === null ? $query : $query->where('phone', 'like', "%{$digits}%");
    }
}
