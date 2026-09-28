<?php

namespace App\Enums;

enum SmsDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
