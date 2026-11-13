<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderTexts\Pages;

use App\Actions\Orders\OrderConfirmationTexts;
use App\Filament\Resources\OrderTexts\OrderTextResource;
use App\Models\Drop;
use App\Sms\QuietHours;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

class ListOrderTexts extends ListRecords
{
    protected static string $resource = OrderTextResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('textDrop')
                ->label('Text a drop’s orders')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->modalHeading('Text a drop’s customers now')
                ->modalDescription('Everyone with an order still to collect or be delivered who hasn’t had their text gets it now, one text per person. Texts waiting for 8am go now too.')
                ->schema([
                    Select::make('drop')
                        ->label('Drop')
                        ->options(fn (): array => Drop::query()->latest('opens_at')->limit(12)->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Drop::featured()?->id)
                        ->required(),
                    Checkbox::make('quiet_hours')
                        ->label('It’s between 8pm and 8am. Text customers now anyway.')
                        ->accepted()
                        ->visible(fn (): bool => QuietHours::contains(now())),
                ])
                ->modalSubmitActionLabel('Send texts')
                ->action(function (array $data, OrderConfirmationTexts $texts): void {
                    $drop = Drop::query()->findOrFail(is_numeric($data['drop'] ?? null) ? (int) $data['drop'] : 0);
                    $sent = $texts->sendNow($drop);

                    Notification::make()
                        ->title($sent === 0 ? 'Nobody is waiting for a text' : 'Texting '.Number::format($sent).' '.str('person')->plural($sent))
                        ->success()
                        ->send();
                }),
        ];
    }
}
