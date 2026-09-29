<?php

namespace App\Models;

use App\Enums\BroadcastStatus;
use App\Sms\SmsText;
use Carbon\CarbonImmutable;
use Database\Factories\SmsBroadcastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A text sent to the wait list, such as a drop announcement.
 *
 * @property int $id
 * @property string $body
 * @property array{postcodes: list<string>, subscribers?: list<int>} $audience
 * @property int|null $drop_id
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Drop|null $drop
 */
#[Fillable(['body', 'audience', 'drop_id', 'scheduled_for', 'started_at', 'finished_at', 'created_by'])]
class SmsBroadcast extends Model
{
    /** @use HasFactory<SmsBroadcastFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'audience' => '{"postcodes": [], "subscribers": []}',
    ];

    /**
     * @return HasMany<SmsMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    /**
     * @return BelongsTo<Drop, $this>
     */
    public function drop(): BelongsTo
    {
        return $this->belongsTo(Drop::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function status(): BroadcastStatus
    {
        return match (true) {
            $this->finished_at !== null => BroadcastStatus::Sent,
            $this->started_at !== null => BroadcastStatus::Sending,
            $this->scheduled_for !== null => BroadcastStatus::Scheduled,
            default => BroadcastStatus::Draft,
        };
    }

    /**
     * The postcodes it goes to. Empty means everyone subscribed.
     *
     * @return list<string>
     */
    public function postcodes(): array
    {
        return $this->audience['postcodes'];
    }

    /**
     * The subscribers it goes to, when it's for chosen people such as testers. Empty means the postcodes decide.
     *
     * @return list<int>
     */
    public function subscriberIds(): array
    {
        return $this->audience['subscribers'] ?? [];
    }

    /**
     * Who it would go to if it started now. Chosen subscribers who have opted out are still left out.
     *
     * @return Builder<Subscriber>
     */
    public function recipients(): Builder
    {
        return Subscriber::query()
            ->subscribed()
            ->when($this->subscriberIds() !== [], fn (Builder $query) => $query->whereKey($this->subscriberIds()))
            ->when($this->subscriberIds() === [] && $this->postcodes() !== [], fn (Builder $query) => $query->whereIn('postcode', $this->postcodes()));
    }

    /**
     * Who it goes to, in a few words.
     */
    public function audienceLabel(): string
    {
        return match (true) {
            $this->subscriberIds() !== [] => count($this->subscriberIds()).' chosen '.str('subscriber')->plural(count($this->subscriberIds())),
            $this->postcodes() !== [] => 'Postcodes '.implode(', ', $this->postcodes()),
            default => 'Everyone subscribed',
        };
    }

    /**
     * The text as subscribers receive it.
     */
    public function text(): SmsText
    {
        return SmsText::forBroadcast($this->body);
    }

    /**
     * Scheduled broadcasts whose time has come.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function due(Builder $query): Builder
    {
        return $query->whereNull('started_at')->where('scheduled_for', '<=', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'scheduled_for' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
