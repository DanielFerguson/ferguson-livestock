<?php

namespace Database\Factories;

use App\Models\Drop;
use App\Models\DropItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Drop>
 */
class DropFactory extends Factory
{
    /**
     * A published drop opening next week by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->monthName().' drop',
            'opens_at' => now()->addWeek()->startOfHour(),
            'closes_at' => null,
            'closed_at' => null,
            'published_at' => now(),
            'delivery_days' => [now()->addWeek()->next('Saturday')->toDateString()],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['published_at' => null]);
    }

    /**
     * An unpublished drop whose date is on the website, with no stock or prices yet.
     */
    public function announced(): static
    {
        return $this->draft()->state(fn () => ['announced_at' => now()]);
    }

    /**
     * Opened an hour ago and still taking orders.
     */
    public function open(): static
    {
        return $this->state(fn () => ['opens_at' => now()->subHour()]);
    }

    /**
     * Opened a month ago and closed by hand a week later.
     */
    public function closed(): static
    {
        return $this->state(fn () => ['opens_at' => now()->subMonth(), 'closed_at' => now()->subMonth()->addWeek()]);
    }

    /**
     * With one individual cut in stock.
     */
    public function withStock(): static
    {
        return $this->has(DropItem::factory()->state(['quantity' => 10]), 'items');
    }
}
