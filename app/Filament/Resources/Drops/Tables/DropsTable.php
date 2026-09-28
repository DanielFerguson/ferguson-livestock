<?php

namespace App\Filament\Resources\Drops\Tables;

use App\Filament\Resources\Drops\Actions\DropActions;
use App\Models\Drop;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DropsTable
{
    public static function configure(Table $table): Table
    {
        $timezone = config()->string('shop.timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('items'))
            ->defaultSort('opens_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('opens_at')
                    ->label('Orders open')
                    ->dateTime('D j M Y, g:ia T', $timezone)
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (Drop $record) => $record->status())
                    ->badge(),
                TextColumn::make('preflight')
                    ->label('Pre-flight')
                    ->state(fn (Drop $record): string => match (true) {
                        $record->preflight_report === null => 'Not run',
                        $record->preflight_report['passed'] => 'Passed',
                        default => count($record->preflight_report['problems']).' to fix',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Passed' => 'success',
                        'Not run' => 'gray',
                        default => 'danger',
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    DropActions::runPreflight(),
                    DropActions::duplicate(),
                    DropActions::closeNow(),
                ]),
            ]);
    }
}
