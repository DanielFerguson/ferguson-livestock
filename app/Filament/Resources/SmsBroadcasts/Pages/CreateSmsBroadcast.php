<?php

namespace App\Filament\Resources\SmsBroadcasts\Pages;

use App\Filament\Resources\SmsBroadcasts\Schemas\SmsBroadcastForm;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSmsBroadcast extends CreateRecord
{
    protected static string $resource = SmsBroadcastResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...SmsBroadcastForm::withAudience($data), 'created_by' => auth()->id()];
    }

    /**
     * Sending and scheduling happen from the broadcast's page, once it's saved.
     */
    protected function getRedirectUrl(): string
    {
        return SmsBroadcastResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
