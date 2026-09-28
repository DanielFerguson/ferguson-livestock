<?php

namespace App\Models;

use App\Enums\DropStatus;
use App\Models\Concerns\ClearsResponseCache;
use Carbon\CarbonImmutable;
use Database\Factories\DropFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A timed release of stock. Only one drop is open at a time: a drop closes at `closed_at`, `closes_at`
 * or when the next published drop opens, whichever comes first.
 *
 * @property int $id
 * @property string $name
 * @property CarbonImmutable $opens_at
 * @property CarbonImmutable|null $closes_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $published_at
 * @property list<string> $delivery_days
 * @property CarbonImmutable|null $preflight_ran_at
 * @property array{passed: bool, problems: list<string>, checked_at: string}|null $preflight_report
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, DropItem> $items
 */
#[Fillable(['name', 'opens_at', 'closes_at', 'closed_at', 'published_at', 'delivery_days', 'preflight_ran_at', 'preflight_report'])]
class Drop extends Model
{
    use ClearsResponseCache;

    /** @use HasFactory<DropFactory> */
    use HasFactory;

    /**
     * @return HasMany<DropItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DropItem::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The published drop customers can order from right now, if any.
     */
    public static function current(): ?self
    {
        return self::query()
            ->published()
            ->where('opens_at', '<=', now())
            ->whereNull('closed_at')
            ->where(fn (Builder $query) => $query->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->orderByDesc('opens_at')
            ->first();
    }

    /**
     * The drop whose prices the marketing pages show: the open drop, else the next scheduled one, else the last one.
     */
    public static function featured(): ?self
    {
        return self::current()
            ?? self::query()->published()->where('opens_at', '>', now())->orderBy('opens_at')->first()
            ?? self::query()->published()->where('opens_at', '<=', now())->orderByDesc('opens_at')->first();
    }

    /**
     * A published drop whose opening clashes with this window: the same opening time, one opening inside
     * this window's explicit close time, or this one opening inside another's. Opening after a drop with
     * no close time is fine; that simply closes the earlier drop.
     */
    public static function conflictingWith(CarbonImmutable $opensAt, ?CarbonImmutable $closesAt, ?int $ignoring = null): ?self
    {
        return self::query()
            ->published()
            ->when($ignoring !== null, fn (Builder $query) => $query->whereKeyNot($ignoring))
            ->where(fn (Builder $query) => $query
                ->where('opens_at', $opensAt)
                ->when($closesAt !== null, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                    ->where('opens_at', '>', $opensAt)
                    ->where('opens_at', '<', $closesAt)))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNotNull('closes_at')
                    ->where('opens_at', '<', $opensAt)
                    ->where('closes_at', '>', $opensAt)))
            ->first();
    }

    public function status(): DropStatus
    {
        if ($this->published_at === null) {
            return DropStatus::Draft;
        }

        if (now()->lt($this->opens_at)) {
            return DropStatus::Scheduled;
        }

        $closesAt = $this->effectiveClosesAt();

        if ($closesAt !== null && now()->gte($closesAt)) {
            return DropStatus::Closed;
        }

        $inStock = $this->items->contains(fn (DropItem $item) => $item->hasOwnStock() && $item->available > 0);

        return $inStock ? DropStatus::Live : DropStatus::SoldOut;
    }

    /**
     * Stop orders now. Customers already in checkout can still pay for what they're holding.
     */
    public function closeNow(): void
    {
        $this->update(['closed_at' => now()]);
    }

    /**
     * A draft copy one week later, with the same products and prices and every unit available again.
     */
    public function duplicateAsDraft(): self
    {
        return DB::transaction(function (): self {
            $copy = $this->replicate(['closed_at', 'published_at', 'preflight_ran_at', 'preflight_report']);
            $copy->name = "{$this->name} (copy)";
            $copy->opens_at = $this->opens_at->addWeek();
            $copy->closes_at = $this->closes_at?->addWeek();
            $copy->delivery_days = array_map(
                fn (string $day): string => CarbonImmutable::parse($day)->addWeek()->toDateString(),
                $this->delivery_days,
            );
            $copy->save();

            foreach ($this->items()->orderBy('id')->get() as $item) {
                $copy->items()->create([
                    ...$item->only('product_id', 'price', 'stripe_price_id', 'quantity', 'max_per_order'),
                    'available' => $item->quantity,
                ]);
            }

            return $copy->load('items');
        });
    }

    /**
     * When ordering stops: closed by hand, the close time, or the next published drop opening.
     */
    public function effectiveClosesAt(): ?CarbonImmutable
    {
        $nextOpensAt = self::query()
            ->published()
            ->whereKeyNot($this->getKey())
            ->where('opens_at', '>', $this->opens_at)
            ->min('opens_at');

        $candidates = array_filter([
            $this->closed_at,
            $this->closes_at,
            is_string($nextOpensAt) || $nextOpensAt instanceof \DateTimeInterface ? CarbonImmutable::parse($nextOpensAt) : null,
        ]);

        return $candidates === [] ? null : min($candidates);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opens_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'delivery_days' => 'array',
            'preflight_ran_at' => 'immutable_datetime',
            'preflight_report' => 'array',
        ];
    }
}
