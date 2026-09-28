<?php

namespace Database\Factories;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Drop;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * A paid delivery order by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'drop_id' => Drop::factory()->open(),
            'status' => OrderStatus::Paid,
            'delivery_method' => DeliveryMethod::Delivery,
            'delivery_day' => now()->addDays(3)->toDateString(),
            'customer_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => '+614'.fake()->numerify('########'),
            'shipping_address' => [
                'line1' => fake()->streetAddress(),
                'line2' => null,
                'city' => fake()->randomElement(['Ballarat', 'Creswick', 'Buninyong', 'Smythesdale']),
                'state' => 'VIC',
                'postal_code' => fake()->randomElement(['3350', '3363', '3352', '3351']),
                'country' => 'AU',
            ],
            'total' => 17500,
            'stripe_checkout_session_id' => 'cs_test_'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'session_fingerprint' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addMinutes(31),
            'paid_at' => now(),
        ];
    }

    /**
     * Holding stock while the customer is at Stripe's payment page.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Pending,
            'customer_name' => null,
            'email' => null,
            'phone' => null,
            'shipping_address' => null,
            'stripe_payment_intent_id' => null,
            'paid_at' => null,
        ]);
    }

    public function pickup(): static
    {
        return $this->state(fn () => ['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null]);
    }
}
