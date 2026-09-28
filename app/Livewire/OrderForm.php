<?php

namespace App\Livewire;

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Enums\DropStatus;
use App\Enums\ProductType;
use App\Exceptions\DropNotOpen;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\PaymentProviderUnavailable;
use App\Exceptions\TooManyCheckoutAttempts;
use App\Models\Drop;
use App\Models\DropItem;
use App\Stock\DropSnapshot;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The order page's form: a box, extras, and delivery or pickup.
 *
 * Choosing things happens in the browser (Alpine sets these properties without asking the server), and the
 * live stock script trims the choices as stock runs out. The one request is "Continue to payment", where the
 * server decides everything again.
 */
class OrderForm extends Component
{
    #[Locked]
    public int $dropId;

    /**
     * The chosen box's drop item ID, 'none' for extras only, or '' before the customer has chosen.
     */
    public string $box = '';

    /**
     * Drop item ID => quantity, for every extra (0 when not chosen).
     *
     * @var array<int, int>
     */
    public array $extras = [];

    public string $deliveryMethod = '';

    public string $deliveryDay = '';

    /**
     * What changed about the order because stock ran out.
     *
     * @var list<string>
     */
    public array $notices = [];

    public ?string $problem = null;

    public function mount(Drop $drop): void
    {
        $this->dropId = $drop->id;
        $this->extras = $this->itemsOfType($this->drop(), ProductType::Extra)->mapWithKeys(fn (DropItem $item): array => [$item->id => 0])->all();
    }

    public function checkout(StartCheckout $start): void
    {
        $this->notices = [];
        $this->problem = null;
        $drop = $this->drop();
        $deliveryMethod = DeliveryMethod::tryFrom($this->deliveryMethod);

        if ($deliveryMethod === null) {
            $this->problem = 'Choose delivery or farm pickup.';

            return;
        }

        try {
            $url = $start(new CheckoutRequest($drop, $this->quantities(), $deliveryMethod, $this->chosenDay(), session()->getId(), (string) request()->ip()));
        } catch (InsufficientStock $exception) {
            $this->cutTo($drop, $exception->available);

            return;
        } catch (InvalidCheckout|DropNotOpen|TooManyCheckoutAttempts $exception) {
            $this->problem = $exception->getMessage();

            return;
        } catch (PaymentProviderUnavailable) {
            $this->problem = 'We couldn’t reach our payment provider, so your order wasn’t placed. Please try again in a minute.';

            return;
        }

        $this->redirect($url);
    }

    public function render(): View
    {
        $drop = $this->drop();
        $snapshot = DropSnapshot::current();
        $stock = $snapshot['items'];
        $available = fn (DropItem $item): int => $stock[$item->product->slug]['available'] ?? 0;

        return view('livewire.order-form', [
            'drop' => $drop,
            'status' => $drop->status(),
            'boxes' => $this->itemsOfType($drop, ProductType::Box),
            'extraItems' => $this->itemsOfType($drop, ProductType::Extra),
            'deliveryFee' => $this->itemsOfType($drop, ProductType::Delivery)->first(),
            'available' => $available,
            // What the browser needs to add up the order and trim it as stock runs out.
            'items' => $drop->items
                ->reject(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery)
                ->mapWithKeys(fn (DropItem $item): array => [$item->id => [
                    'slug' => $item->product->slug,
                    'name' => $item->product->name,
                    'price' => $item->price,
                    'max' => $item->max_per_order,
                    'available' => $available($item),
                    'box' => $item->product->type === ProductType::Box,
                ]])
                ->all(),
            'held' => $snapshot['held'],
            'cancelNotice' => session('checkout.notice'),
        ]);
    }

    private function drop(): Drop
    {
        return Drop::query()->with('items.product')->findOrFail($this->dropId);
    }

    /**
     * @return Collection<int, DropItem>
     */
    private function itemsOfType(Drop $drop, ProductType $type): Collection
    {
        return $drop->items
            ->filter(fn (DropItem $item): bool => $item->product->type === $type)
            ->sortBy(fn (DropItem $item): int => $item->product->sort)
            ->values()
            ->toBase();
    }

    /**
     * @return array<int, int>
     */
    private function quantities(): array
    {
        $quantities = array_filter($this->extras, fn (int $quantity): bool => $quantity > 0);

        if (is_numeric($this->box)) {
            $quantities[(int) $this->box] = 1;
        }

        return $quantities;
    }

    private function chosenDay(): ?CarbonImmutable
    {
        try {
            return $this->deliveryDay === '' ? null : CarbonImmutable::parse($this->deliveryDay);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Someone else got there first. Trim the order to what's left and say what changed, in the same words the
     * browser uses when it trims the order itself.
     *
     * @param  array<int, int>  $available
     */
    private function cutTo(Drop $drop, array $available): void
    {
        foreach ($available as $dropItemId => $left) {
            $item = $drop->items->firstWhere('id', $dropItemId);
            $name = $item instanceof DropItem ? $item->product->name : 'item';

            if ((string) $dropItemId === $this->box) {
                $this->box = '';
                $this->notices[] = "The {$name} just sold out, so we’ve taken it off your order.";

                continue;
            }

            $this->extras[$dropItemId] = $left;
            $this->notices[] = $left > 0
                ? "There are only {$left} of the {$name} left, so we’ve changed your order to {$left}."
                : "The {$name} just sold out, so we’ve taken it off your order.";
        }

        if ($available === []) {
            $this->problem = 'Something in your order just sold out. Please check it and try again.';
        }
    }

    public function isTakingOrders(): bool
    {
        return in_array($this->drop()->status(), [DropStatus::Live, DropStatus::SoldOut], true);
    }
}
