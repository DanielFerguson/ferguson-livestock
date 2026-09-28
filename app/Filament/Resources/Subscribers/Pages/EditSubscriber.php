<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Resources\Subscribers\Actions\SubscriberActions;
use App\Filament\Resources\Subscribers\SubscriberResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscriber extends EditRecord
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SubscriberActions::optOut(),
            DeleteAction::make()
                ->modalDescription('Removes their details for good, e.g. when someone asks you to delete their information. Their past texts stay, without their name.'),
        ];
    }
}
