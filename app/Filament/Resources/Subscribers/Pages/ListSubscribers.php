<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Imports\SubscriberImporter;
use App\Filament\Resources\Subscribers\Actions\SubscriberActions;
use App\Filament\Resources\Subscribers\SubscriberResource;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SubscriberActions::downloadCsv(),
            ImportAction::make()
                ->label('Import from Klaviyo')
                ->importer(SubscriberImporter::class)
                ->modalDescription('Upload Klaviyo’s CSV export of your SMS list. People without SMS consent, and anyone already on this list, are skipped.'),
        ];
    }
}
