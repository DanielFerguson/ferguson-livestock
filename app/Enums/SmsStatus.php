<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a text is up to. Outbound texts only move forward, because Twilio's status updates can arrive out of order.
 */
enum SmsStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Failed = 'failed';
    /** The subscriber opted out between the broadcast starting and their text being sent. */
    case Skipped = 'skipped';
    case Received = 'received';

    /**
     * Twilio's many statuses, grouped into what matters here.
     */
    public static function fromTwilio(string $status): ?self
    {
        return match ($status) {
            'accepted', 'scheduled', 'queued', 'sending', 'sent' => self::Sent,
            'delivered', 'read' => self::Delivered,
            'undelivered' => self::Undelivered,
            'failed', 'canceled' => self::Failed,
            default => null,
        };
    }

    public function isAfter(self $status): bool
    {
        return $this->stage() > $status->stage();
    }

    public function isFinal(): bool
    {
        return $this->stage() === 3;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Waiting',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Undelivered => 'Not delivered',
            self::Failed => 'Failed',
            self::Skipped => 'Opted out first',
            self::Received => 'Received',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Sending, self::Skipped => 'gray',
            self::Sent => 'info',
            self::Delivered, self::Received => 'success',
            self::Undelivered, self::Failed => 'danger',
        };
    }

    private function stage(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Sending => 1,
            self::Sent => 2,
            self::Delivered, self::Undelivered, self::Failed, self::Skipped, self::Received => 3,
        };
    }
}
