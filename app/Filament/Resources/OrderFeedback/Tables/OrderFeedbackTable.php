<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderFeedback\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderFeedback;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderFeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('order'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('updated_at')->label('Left')->dateTime('D j M, g:ia', config()->string('shop.timezone'))->sortable(),
                TextColumn::make('rating')->formatStateUsing(fn (OrderFeedback $record): string => $record->stars())->sortable(),
                TextColumn::make('comment')->wrap()->placeholder('No comment'),
                TextColumn::make('order.customer_name')->label('Customer'),
                TextColumn::make('reference')
                    ->label('Order')
                    ->state(fn (OrderFeedback $record): string => $record->order->reference())
                    ->url(fn (OrderFeedback $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
            ]);
    }
}
