<?php

namespace Database\Factories;

use App\Models\SmsBroadcast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsBroadcast>
 */
class SmsBroadcastFactory extends Factory
{
    /**
     * A draft to everyone subscribed by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => 'Our next beef drop opens Saturday at 9am.',
            'audience' => ['postcodes' => []],
            'scheduled_for' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @param  list<string>  $postcodes
     */
    public function forPostcodes(array $postcodes): static
    {
        return $this->state(fn () => ['audience' => ['postcodes' => $postcodes]]);
    }

    /**
     * @param  list<int>  $subscriberIds
     */
    public function forSubscribers(array $subscriberIds): static
    {
        return $this->state(fn () => ['audience' => ['postcodes' => [], 'subscribers' => $subscriberIds]]);
    }

    public function scheduledFor(mixed $moment): static
    {
        return $this->state(fn () => ['scheduled_for' => $moment]);
    }

    public function sent(): static
    {
        return $this->state(fn () => ['started_at' => now()->subHour(), 'finished_at' => now()->subMinutes(50)]);
    }
}
