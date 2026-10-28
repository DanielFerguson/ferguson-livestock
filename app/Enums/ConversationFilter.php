<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which conversations the inbox shows.
 */
enum ConversationFilter: string implements HasLabel
{
    case All = 'all';
    case NeedsReply = 'needs_reply';
    case Unread = 'unread';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'All',
            self::NeedsReply => 'Needs reply',
            self::Unread => 'Unread',
        };
    }
}
