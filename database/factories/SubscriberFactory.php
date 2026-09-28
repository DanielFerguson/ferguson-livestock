<?php

namespace Database\Factories;

use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'phone' => '+614'.fake()->unique()->numerify('########'),
            'postcode' => fake()->randomElement(['3350', '3351', '3352', '3355', '3356']),
            'consented_at' => now(),
            'consent_source' => 'website wait list',
            'consent_wording' => Subscriber::CONSENT_WORDING,
            'consent_ip' => fake()->ipv4(),
            'unsubscribed_at' => null,
        ];
    }

    /**
     * Someone who has opted out of drop announcements.
     */
    public function unsubscribed(): static
    {
        return $this->state(fn () => ['unsubscribed_at' => now()]);
    }
}
