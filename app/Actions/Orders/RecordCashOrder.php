<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Exceptions\CannotRecordOrder;
use App\Exceptions\CheckoutBusy;
use App\Exceptions\InsufficientStock;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Stock\StockLedger;
use App\Support\AustralianMobile;
use Carbon\CarbonImmutable;

/**
 * Records an order the owner takes for family or friends who pay in cash, never for customers. It holds stock like a
 * website order and is priced the same, but it can be put aside before the drop opens and isn't held to the limits
 * customers are, like one box per order. Nothing is sent: no emails and no Meta event.
 */
final readonly class RecordCashOrder
{
    public function __construct(private StockLedger $stock) {}

    /**
     * @param  array<int, int>  $quantities  drop item ID => quantity, without the delivery fee: it's added for deliveries
     * @param  array{line1?: string|null, city?: string|null, postal_code?: string|null}|null  $address  where to deliver
     * @param  bool  $paid  the cash has been handed over already; otherwise the order waits as Cash owed
     *
     * @throws CannotRecordOrder
     */
    public function __invoke(
        Drop $drop,
        array $quantities,
        DeliveryMethod $deliveryMethod,
        ?CarbonImmutable $deliveryDay,
        string $customerName,
        ?string $phone,
        ?array $address,
        bool $paid,
    ): Order {
        $drop->loadMissing('items.product');

        if ($drop->hasEnded()) {
            throw new CannotRecordOrder("{$drop->name} has closed, so it can’t take new orders.");
        }

        $quantities = $this->items($drop, $quantities);

        if (trim($customerName) === '') {
            throw new CannotRecordOrder('Add the name of the person the order is for.');
        }

        $mobile = $this->mobile($phone);
        $shippingAddress = null;

        if ($deliveryMethod === DeliveryMethod::Delivery) {
            $quantities[$this->deliveryFee($drop, $deliveryDay)->id] = 1;
            $shippingAddress = $this->shippingAddress($address);

            if ($mobile === null) {
                throw new CannotRecordOrder('Add a mobile number for the delivery.');
            }
        }

        try {
            return $this->stock->setAside($drop, $quantities, [
                'status' => $paid ? OrderStatus::Paid : OrderStatus::CashOwed,
                'payment_method' => PaymentMethod::Cash,
                'delivery_method' => $deliveryMethod,
                'delivery_day' => $deliveryMethod === DeliveryMethod::Delivery ? $deliveryDay : null,
                'customer_name' => trim($customerName),
                'phone' => $mobile,
                'shipping_address' => $shippingAddress,
                'paid_at' => $paid ? now() : null,
            ])->refresh();
        } catch (InsufficientStock $exception) {
            throw new CannotRecordOrder($exception->describe($drop), $exception->getCode(), previous: $exception);
        } catch (CheckoutBusy $exception) {
            throw new CannotRecordOrder('Lots of orders are coming in right now. Try again in a moment.', $exception->getCode(), previous: $exception);
        }
    }

    /**
     * @param  array<int, int>  $quantities
     * @return array<int, int>
     *
     * @throws CannotRecordOrder
     */
    private function items(Drop $drop, array $quantities): array
    {
        $quantities = array_filter($quantities, fn (int $quantity): bool => $quantity > 0);

        if ($quantities === []) {
            throw new CannotRecordOrder('Choose at least one item.');
        }

        foreach (array_keys($quantities) as $dropItemId) {
            $item = $drop->items->first(fn (DropItem $item): bool => $item->id === $dropItemId);

            if (! $item instanceof DropItem || $item->product->type === ProductType::Delivery) {
                throw new CannotRecordOrder("Something in this order isn’t sold in {$drop->name}.");
            }
        }

        return $quantities;
    }

    /**
     * @throws CannotRecordOrder
     */
    private function mobile(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        return AustralianMobile::toE164($phone) ?? throw new CannotRecordOrder("{$phone} isn’t an Australian mobile number.");
    }

    /**
     * @throws CannotRecordOrder
     */
    private function deliveryFee(Drop $drop, ?CarbonImmutable $deliveryDay): DropItem
    {
        if (! $deliveryDay instanceof CarbonImmutable || ! in_array($deliveryDay->toDateString(), $drop->delivery_days, true)) {
            throw new CannotRecordOrder("Choose one of {$drop->name}’s delivery days.");
        }

        $fee = $drop->items->first(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery);

        return $fee instanceof DropItem ? $fee : throw new CannotRecordOrder("{$drop->name} has no delivery fee, so its orders can only be picked up.");
    }

    /**
     * @param  array{line1?: string|null, city?: string|null, postal_code?: string|null}|null  $address
     * @return array{line1: string, line2: null, city: string, state: string, postal_code: string, country: string}
     *
     * @throws CannotRecordOrder
     */
    private function shippingAddress(?array $address): array
    {
        $street = trim((string) ($address['line1'] ?? ''));
        $suburb = trim((string) ($address['city'] ?? ''));
        $postcode = trim((string) ($address['postal_code'] ?? ''));

        if ($street === '' || $suburb === '' || $postcode === '') {
            throw new CannotRecordOrder('Add the street, suburb and postcode to deliver to.');
        }

        if (preg_match('/^\d{4}$/', $postcode) !== 1) {
            throw new CannotRecordOrder("{$postcode} isn’t a postcode. Use its four digits.");
        }

        return ['line1' => $street, 'line2' => null, 'city' => $suburb, 'state' => 'VIC', 'postal_code' => $postcode, 'country' => 'AU'];
    }
}
