<?php

namespace App\Filament\Resources\SmsBroadcasts\Schemas;

use App\Models\Drop;
use App\Models\SmsBroadcast;
use App\Models\Subscriber;
use App\Sms\BroadcastPreview;
use App\Sms\SmsText;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class SmsBroadcastForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Message')
                    ->schema([
                        Textarea::make('body')
                            ->label('Message')
                            ->required()
                            ->rows(4)
                            ->maxLength(640)
                            ->live(debounce: 500)
                            ->helperText('“Ferguson Livestock:” is added at the start and “Reply STOP to opt out” at the end, as the Spam Act requires. Curly quotes and dashes become plain ones, so they don’t halve what each text holds.')
                            ->dehydrateStateUsing(fn (string $state): string => SmsText::tidy($state)),
                        TextEntry::make('preview')
                            ->label('What subscribers receive')
                            ->state(fn (Get $get): HtmlString => self::asLines(self::preview($get)->text->text)),
                        TextEntry::make('length')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => self::preview($get)->length()),
                        TextEntry::make('alphabet_warning')
                            ->hiddenLabel()
                            ->color('warning')
                            ->state(fn (Get $get): ?string => self::preview($get)->alphabetWarning())
                            ->visible(fn (Get $get): bool => self::preview($get)->alphabetWarning() !== null),
                    ]),
                Section::make('Who gets it')
                    ->schema([
                        Radio::make('audience_scope')
                            ->label('Send to')
                            ->options(['everyone' => 'Everyone subscribed', 'postcodes' => 'Only some postcodes'])
                            ->default('everyone')
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(fn (Radio $component, ?SmsBroadcast $record) => $component->state(
                                $record !== null && $record->postcodes() !== [] ? 'postcodes' : 'everyone',
                            )),
                        Select::make('audience.postcodes')
                            ->label('Postcodes')
                            ->multiple()
                            ->options(fn (): array => self::postcodeOptions())
                            ->required()
                            ->visible(fn (Get $get): bool => $get('audience_scope') === 'postcodes'),
                        TextEntry::make('reach')
                            ->label('Reaches')
                            ->state(fn (Get $get): string => self::preview($get)->reach()),
                        Select::make('drop_id')
                            ->label('About a drop (optional)')
                            ->options(fn (): array => Drop::query()->orderByDesc('opens_at')->limit(20)->pluck('name', 'id')->all())
                            ->helperText('Scheduling then suggests the time the drop opens.'),
                    ]),
            ]);
    }

    /**
     * The audience as stored: a list of postcode strings, empty for everyone. The select hands back postcodes as
     * integers (PHP turns numeric array keys into ints), and none at all when "Everyone subscribed" hides it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withAudience(array $data): array
    {
        $postcodes = is_array($data['audience'] ?? null) ? ($data['audience']['postcodes'] ?? []) : [];
        $postcodes = array_filter(is_array($postcodes) ? $postcodes : [], fn (mixed $postcode): bool => is_string($postcode) || is_int($postcode));

        return [...$data, 'audience' => ['postcodes' => array_values(array_map(strval(...), $postcodes))]];
    }

    /**
     * The broadcast as it stands in the form, unsaved.
     */
    private static function preview(Get $get): BroadcastPreview
    {
        $postcodes = $get('audience_scope') === 'postcodes' ? $get('audience.postcodes') : [];

        return BroadcastPreview::of(new SmsBroadcast(self::withAudience([
            'body' => is_string($get('body')) ? $get('body') : '',
            'audience' => ['postcodes' => $postcodes],
        ])));
    }

    /**
     * Each postcode on the list with how many subscribers it has, e.g. "3350 (42)".
     *
     * @return array<string, string>
     */
    private static function postcodeOptions(): array
    {
        return Subscriber::query()
            ->subscribed()
            ->whereNotNull('postcode')
            ->orderBy('postcode')
            ->pluck('postcode')
            ->countBy()
            ->map(fn (int $count, string $postcode): string => "{$postcode} ({$count})")
            ->all();
    }

    private static function asLines(string $text): HtmlString
    {
        return new HtmlString(nl2br(e($text)));
    }
}
