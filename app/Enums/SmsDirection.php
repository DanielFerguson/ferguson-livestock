<?php

declare(strict_types=1);

namespace App\Enums;

enum SmsDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
