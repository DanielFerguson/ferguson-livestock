<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What the owner has decided about one conversation: flagged to come back to, or ticked off as done.
 *
 * @property int $id
 * @property string $contact_phone
 * @property CarbonImmutable|null $follow_up_at
 * @property CarbonImmutable|null $done_at
 */
#[Fillable(['contact_phone', 'follow_up_at', 'done_at'])]
class ConversationState extends Model
{
    public static function followUp(string $phone): void
    {
        self::updateOrCreate(['contact_phone' => $phone], ['follow_up_at' => now(), 'done_at' => null]);
    }

    public static function clearFollowUp(string $phone): void
    {
        self::query()->where('contact_phone', $phone)->update(['follow_up_at' => null]);
    }

    public static function markDone(string $phone): void
    {
        self::updateOrCreate(['contact_phone' => $phone], ['follow_up_at' => null, 'done_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'follow_up_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
        ];
    }
}
