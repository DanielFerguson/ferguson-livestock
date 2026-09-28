<?php

namespace App\Filament\Resources\SmsBroadcasts\RelationManagers;

use App\Enums\SmsStatus;
use App\Models\SmsBroadcast;
use App\Support\AustralianMobile;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Each person a broadcast went to, and what happened to their text.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Recipients';

    /**
     * Twilio's error codes that come up with Australian mobiles, in plain English.
     */
    private const array ERRORS = [
        21211 => 'Not a valid number',
        21610 => 'They replied STOP to Twilio',
        21614 => 'Not a mobile number',
        30003 => 'Phone off or out of range',
        30005 => 'Number no longer in use',
        30006 => 'Can’t receive texts',
        30007 => 'Blocked by their phone company as spam',
        30008 => 'Unknown problem at their phone company',
    ];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof SmsBroadcast && $ownerRecord->started_at !== null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $timezone = config()->string('shop.timezone');

        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('subscriber.first_name')->label('Name')->placeholder('—'),
                TextColumn::make('to')->label('Mobile')->formatStateUsing(fn (string $state): string => AustralianMobile::format($state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('error_code')
                    ->label('Why')
                    ->formatStateUsing(fn (int $state): string => self::ERRORS[$state] ?? "Twilio error {$state}")
                    ->placeholder(''),
                TextColumn::make('sent_at')->dateTime('g:ia', $timezone)->placeholder('—'),
                TextColumn::make('delivered_at')->dateTime('g:ia', $timezone)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(SmsStatus::class),
            ]);
    }
}
