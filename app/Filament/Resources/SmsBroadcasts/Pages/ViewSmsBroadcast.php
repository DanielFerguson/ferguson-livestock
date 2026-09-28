<?php

namespace App\Filament\Resources\SmsBroadcasts\Pages;

use App\Filament\Resources\SmsBroadcasts\Actions\BroadcastActions;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use App\Models\SmsBroadcast;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSmsBroadcast extends ViewRecord
{
    protected static string $resource = SmsBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (SmsBroadcast $record): bool => SmsBroadcastResource::canEdit($record)),
            BroadcastActions::sendTest(),
            BroadcastActions::schedule(),
            BroadcastActions::unschedule(),
            BroadcastActions::sendNow(),
            DeleteAction::make()->visible(fn (SmsBroadcast $record): bool => SmsBroadcastResource::canDelete($record)),
        ];
    }
}
