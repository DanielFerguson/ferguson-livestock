<?php

namespace App\Filament\Resources\SmsBroadcasts\Pages;

use App\Filament\Resources\SmsBroadcasts\Schemas\SmsBroadcastForm;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSmsBroadcast extends EditRecord
{
    protected static string $resource = SmsBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SmsBroadcastForm::withAudience($data);
    }

    protected function getRedirectUrl(): string
    {
        return SmsBroadcastResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
