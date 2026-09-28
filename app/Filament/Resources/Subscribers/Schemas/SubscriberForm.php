<?php

namespace App\Filament\Resources\Subscribers\Schemas;

use App\Models\Subscriber;
use App\Support\AustralianMobile;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriberForm
{
    public static function configure(Schema $schema): Schema
    {
        $timezone = config()->string('shop.timezone');

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextInput::make('first_name')->maxLength(60),
                        TextInput::make('phone')
                            ->label('Mobile')
                            ->required()
                            ->tel()
                            ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : AustralianMobile::format($state))
                            ->rule(fn (?Subscriber $record): Closure => self::mobileNotOnTheList($record))
                            ->dehydrateStateUsing(fn (string $state): ?string => AustralianMobile::toE164($state)),
                        TextInput::make('postcode')->maxLength(4)->rule('digits:4'),
                    ]),
                Section::make('Consent to texts')
                    ->description('The Spam Act requires evidence that each person agreed to receive texts.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('consented_at')->label('Agreed')->dateTime('j M Y, g:ia', $timezone),
                        TextEntry::make('consent_source')->label('Where'),
                        TextEntry::make('consent_wording')->label('What they agreed to')->columnSpanFull(),
                        TextEntry::make('consent_ip')->label('IP address')->placeholder('Not recorded'),
                        TextEntry::make('unsubscribed_at')->label('Opted out')->dateTime('j M Y, g:ia', $timezone)->placeholder('Still subscribed'),
                    ]),
            ]);
    }

    private static function mobileNotOnTheList(?Subscriber $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $phone = is_string($value) ? AustralianMobile::toE164($value) : null;

            if ($phone === null) {
                $fail('Enter an Australian mobile number, like 0412 345 678.');

                return;
            }

            if (Subscriber::where('phone', $phone)->when($record !== null, fn ($query) => $query->whereKeyNot($record?->id))->exists()) {
                $fail('Someone on the list already has this number.');
            }
        };
    }
}
