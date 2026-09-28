<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SubscriberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone on the wait list who has agreed to receive drop announcements by text.
 *
 * @property int $id
 * @property string|null $first_name
 * @property string $phone
 * @property string|null $postcode
 * @property CarbonImmutable $consented_at
 * @property string $consent_source
 * @property string $consent_wording
 * @property string|null $consent_ip
 * @property CarbonImmutable|null $unsubscribed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
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
     * Stored for people imported from Klaviyo, whose export has the date they agreed but not the form's wording.
     */
    public const string KLAVIYO_CONSENT_WORDING = 'Agreed to Ferguson Livestock text messages on a Klaviyo sign-up form. Klaviyo recorded the date; its export doesn’t include the form’s wording.';

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
     * @return HasMany<SmsMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function isSubscribed(): bool
    {
        return $this->unsubscribed_at === null;
    }

    /**
     * Stop texting them. Opting out again keeps the original date.
     */
    public function optOut(): void
    {
        $this->unsubscribed_at ??= now();
        $this->save();
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
