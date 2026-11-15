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
     * @param  array<int, int>  $customerOfOrder  which of the customers placed each order, by order ID
     */
    private function __construct(private array $customers, private array $customerOfOrder) {}

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
            ->get()
            ->all();

        /** @var array<int, array{keys: list<string>, positions: list<int>}> $customers */
        $customers = [];

        foreach ($orders as $position => $order) {
            $customer = ['keys' => self::keys($order), 'positions' => [$position]];

            // An order can link customers that looked like two people until now, so every match is merged in.
            foreach ($customers as $index => $other) {
                if (array_intersect($customer['keys'], $other['keys']) !== []) {
                    $customer = [
                        'keys' => array_values(array_unique([...$other['keys'], ...$customer['keys']])),
                        'positions' => [...$other['positions'], ...$customer['positions']],
                    ];
                    unset($customers[$index]);
                }
            }

            $customers[] = $customer;
        }

        $customerOrders = [];
        $customerOfOrder = [];

        foreach (array_values($customers) as $index => $customer) {
            $positions = $customer['positions'];
            sort($positions);

            $customerOrders[] = new Collection(array_map(fn (int $position): Order => $orders[$position], $positions));

            foreach ($positions as $position) {
                $customerOfOrder[$orders[$position]->id] = $index;
            }
        }

        return new self($customerOrders, $customerOfOrder);
    }

    /**
     * The order's customer's placed orders on its drop, this one first and the rest oldest first. An order that
     * wasn't placed (still at checkout, abandoned or failed) is on its own. For a placed order these are the copies
     * onDrop() loaded, not the one passed in, so a caller that changes an order refreshes its own copy.
     *
     * @return Collection<int, Order>
     */
    public function of(Order $order): Collection
    {
        $customer = $this->customerOfOrder[$order->id] ?? null;

        if ($customer === null) {
            return new Collection([$order]);
        }

        return $this->customers[$customer]->sortBy(fn (Order $other): int => $other->is($order) ? 0 : 1)->values();
    }

    /**
     * What ties an order to its customer's other orders: its phone number, email and name, each written one way
     * however it was typed. A name's apostrophes are all made straight, since a phone's keyboard types O’Brien where
     * a computer's types O'Brien. Blank ones are left out, so they never match.
     *
     * @return list<string>
     */
    private static function keys(Order $order): array
    {
        $phone = trim($order->phone ?? '');
        $email = mb_strtolower(trim($order->email ?? ''));
        $singleSpacedName = (string) preg_replace('/\s+/u', ' ', $order->customer_name ?? '');
        $name = mb_strtolower(trim(str_replace(['’', '‘'], "'", $singleSpacedName)));

        return array_values(array_filter([
            $phone === '' ? null : 'phone:'.(AustralianMobile::toE164($phone) ?? $phone),
            $email === '' ? null : "email:{$email}",
            $name === '' ? null : "name:{$name}",
        ]));
    }
}
