<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SubscriberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone on the wait list who has agreed to receive drop announcements by text.
 *
 * @property int $id
 * @property string $first_name
 * @property string $phone
 * @property string $postcode
 * @property CarbonImmutable $consented_at
 * @property string $consent_source
 * @property string $consent_wording
 * @property string|null $consent_ip
 * @property CarbonImmutable|null $unsubscribed_at
 */
#[Fillable(['first_name', 'phone', 'postcode', 'consented_at', 'consent_source', 'consent_wording', 'consent_ip', 'unsubscribed_at'])]
class Subscriber extends Model
{
    /** @use HasFactory<SubscriberFactory> */
    use HasFactory;

    /**
     * What the wait-list checkbox says. Stored with each sign-up as evidence of consent.
     */
    public const string CONSENT_WORDING = 'I agree to receive Ferguson Livestock text messages about beef drops. I can opt out at any time.';

    /**
     * Record a wait-list sign-up. Signing up again refreshes the details and consent, and undoes an earlier opt-out.
     */
    public static function recordSignUp(string $firstName, string $phone, string $postcode, ?string $ip): self
    {
        return self::updateOrCreate(['phone' => $phone], [
            'first_name' => $firstName,
            'postcode' => $postcode,
            'consented_at' => now(),
            'consent_source' => 'website wait list',
            'consent_wording' => self::CONSENT_WORDING,
            'consent_ip' => $ip,
            'unsubscribed_at' => null,
        ]);
    }

    /**
     * Subscribers who can still be sent drop announcements.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function subscribed(Builder $query): Builder
    {
        return $query->whereNull('unsubscribed_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consented_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
        ];
    }
}
