<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderFeedback\Pages;

use App\Filament\Resources\OrderFeedback\OrderFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderFeedback extends ListRecords
{
    protected static string $resource = OrderFeedbackResource::class;
}
