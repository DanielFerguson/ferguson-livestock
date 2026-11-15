<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who placed which of a drop's orders. Some customers order more than once on a drop (an extra box, something they
 * forgot), and all of their orders have to be handed over together.
 *
 * Two orders are the same customer's when they share a phone number, an email or a name, each compared however it
 * was typed. That chains: two orders matching by name, one of which matches a third by phone, are all one
 * customer's. Cash orders often have only a name, so names count too. Two people who share a name are put together,
 * which is why every order is shown by its reference wherever they're grouped.
 */
final readonly class CustomerOrders
{
    /**
     * @param  list<Collection<int, Order>>  $customers  each customer's orders, oldest first
     */
    private function __construct(private array $customers) {}

    /**
     * The drop's placed orders (paid, clearing, fulfilled or owed in cash), grouped by customer.
     *
     * @param  list<string>  $with  relations to load on each order
     */
    public static function onDrop(int $dropId, array $with = []): self
    {
        $orders = Order::query()
            ->where('drop_id', $dropId)
            ->whereIn('status', OrderStatus::placed())
            ->with($with)
            ->oldest()
            ->oldest('id')
            ->get();

        /** @var array<int, array{keys: list<string>, ids: list<int>}> $customers */
        $customers = [];

        foreach ($orders as $order) {
            $customer = ['keys' => self::keys($order), 'ids' => [$order->id]];

            // An order can link customers that looked like two people until now, so every match is merged in.
            foreach ($customers as $index => $other) {
                if (array_intersect($customer['keys'], $other['keys']) !== []) {
                    $customer = ['keys' => [...$other['keys'], ...$customer['keys']], 'ids' => [...$other['ids'], ...$customer['ids']]];
                    unset($customers[$index]);
                }
            }

            $customers[] = $customer;
        }

        return new self(array_values(array_map(
            fn (array $customer): Collection => $orders->whereIn('id', $customer['ids'])->values(),
            $customers,
        )));
    }

    /**
     * The order's customer's placed orders on its drop, this one first and the rest oldest first. An order that
     * wasn't placed (still at checkout, abandoned or failed) is on its own.
     *
     * @return Collection<int, Order>
     */
    public function of(Order $order): Collection
    {
        foreach ($this->customers as $orders) {
            if ($orders->contains('id', $order->id)) {
                return $orders->sortBy(fn (Order $other): int => $other->is($order) ? 0 : 1)->values();
            }
        }

        return new Collection([$order]);
    }

    /**
     * What ties an order to its customer's other orders: its phone number, email and name, each written one way
     * however it was typed. Blank ones are left out, so they never match.
     *
     * @return list<string>
     */
    private static function keys(Order $order): array
    {
        $phone = trim($order->phone ?? '');
        $email = mb_strtolower(trim($order->email ?? ''));
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $order->customer_name ?? '')));

        return array_values(array_filter([
            $phone === '' ? null : 'phone:'.(AustralianMobile::toE164($phone) ?? $phone),
            $email === '' ? null : "email:{$email}",
            $name === '' ? null : "name:{$name}",
        ]));
    }
}
