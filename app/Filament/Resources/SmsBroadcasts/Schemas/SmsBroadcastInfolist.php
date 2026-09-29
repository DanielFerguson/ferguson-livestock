<?php

namespace App\Filament\Resources\SmsBroadcasts\Schemas;

use App\Enums\SmsStatus;
use App\Models\SmsBroadcast;
use App\Sms\BroadcastPreview;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

class SmsBroadcastInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $timezone = config()->string('shop.timezone');

        return $schema
            ->columns(1)
            ->components([
                Section::make('Message')
                    ->schema([
                        TextEntry::make('preview')
                            ->label('What subscribers receive')
                            ->state(fn (SmsBroadcast $record): HtmlString => new HtmlString(nl2br(e($record->text()->text)))),
                        TextEntry::make('length')
                            ->hiddenLabel()
                            ->state(fn (SmsBroadcast $record): string => BroadcastPreview::of($record)->length()),
                        TextEntry::make('alphabet_warning')
                            ->hiddenLabel()
                            ->color('warning')
                            ->state(fn (SmsBroadcast $record): ?string => BroadcastPreview::of($record)->alphabetWarning())
                            ->visible(fn (SmsBroadcast $record): bool => BroadcastPreview::of($record)->alphabetWarning() !== null),
                    ]),
                Section::make('Sending')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('status')
                            ->state(fn (SmsBroadcast $record) => $record->status())
                            ->badge(),
                        TextEntry::make('audience')
                            ->label('To')
                            ->state(fn (SmsBroadcast $record): string => $record->audienceLabel()),
                        TextEntry::make('when')
                            ->label(fn (SmsBroadcast $record): string => $record->started_at !== null ? 'Started' : 'Scheduled for')
                            ->state(fn (SmsBroadcast $record) => $record->started_at ?? $record->scheduled_for)
                            ->dateTime('D j M Y, g:ia T', $timezone)
                            ->placeholder('Not scheduled'),
                        TextEntry::make('reach')
                            ->label('Would reach')
                            ->state(fn (SmsBroadcast $record): string => BroadcastPreview::of($record)->reach())
                            ->visible(fn (SmsBroadcast $record): bool => $record->status()->isEditable()),
                        TextEntry::make('drop.name')
                            ->label('About')
                            ->placeholder('No drop'),
                    ]),
                Section::make('Results')
                    ->visible(fn (SmsBroadcast $record): bool => $record->started_at !== null)
                    ->schema([
                        TextEntry::make('results')
                            ->hiddenLabel()
                            ->state(fn (SmsBroadcast $record): string => self::results($record)),
                    ]),
            ]);
    }

    /**
     * "2 delivered · 1 failed"
     */
    private static function results(SmsBroadcast $record): string
    {
        $counts = $record->messages()->get(['status'])->countBy(fn ($message): string => $message->status->value);

        $parts = collect([
            'delivered' => [SmsStatus::Delivered],
            'sent, waiting for a delivery report' => [SmsStatus::Sent],
            'still to send' => [SmsStatus::Queued, SmsStatus::Sending],
            'not delivered' => [SmsStatus::Undelivered],
            'failed' => [SmsStatus::Failed],
            'skipped because they opted out' => [SmsStatus::Skipped],
        ])
            ->map(fn (array $statuses): int => array_sum(array_map(fn (SmsStatus $status): int => $counts->get($status->value, 0), $statuses)))
            ->filter()
            ->map(fn (int $count, string $label): string => Number::format($count).' '.$label);

        return $parts->isEmpty() ? 'No texts yet' : $parts->implode(' · ');
    }
}
