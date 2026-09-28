<?php

namespace App\Filament\Resources\StripeEvents\Pages;

use App\Filament\Resources\StripeEvents\StripeEventResource;
use Filament\Resources\Pages\ListRecords;

class ListStripeEvents extends ListRecords
{
    protected static string $resource = StripeEventResource::class;
}
