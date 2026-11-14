<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrderFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a customer thought of their order, left on its page: 1 to 5 stars and an optional comment. One per order;
 * leaving it again replaces it.
 *
 * @property int $id
 * @property int $order_id
 * @property int $rating
 * @property string|null $comment
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 */
#[Fillable(['order_id', 'rating', 'comment'])]
class OrderFeedback extends Model
{
    /** @use HasFactory<OrderFeedbackFactory> */
    use HasFactory;

    protected $table = 'order_feedback';

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The rating as stars: "★★★★☆".
     */
    public function stars(): string
    {
        return str_repeat('★', $this->rating).str_repeat('☆', 5 - $this->rating);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }
}
