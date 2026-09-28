<?php

namespace App\Filament\Resources\StripeEvents\Tables;

use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StripeEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->label('Received')->dateTime('D j M, g:i:sa', config()->string('shop.timezone')),
                TextColumn::make('type')->searchable(),
                TextColumn::make('id')->label('Event')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outcome')
                    ->state(fn (StripeEvent $record): string => $record->processed_at !== null ? 'Done' : "Not done ({$record->attempts} tries)")
                    ->badge()
                    ->color(fn (StripeEvent $record): string => $record->processed_at !== null ? 'success' : 'danger'),
                TextColumn::make('last_error')->label('Error')->wrap()->placeholder(''),
            ])
            ->filters([
                TernaryFilter::make('processed')
                    ->label('Done')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('processed_at'),
                        false: fn (Builder $query) => $query->whereNull('processed_at'),
                    ),
            ])
            ->recordActions([
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (StripeEvent $record): bool => $record->processed_at === null)
                    ->action(function (StripeEvent $record): void {
                        ProcessStripeEvent::dispatch($record->id);

                        Notification::make()->title('Queued to try again')->success()->send();
                    }),
            ]);
    }
}
