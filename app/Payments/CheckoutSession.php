<?php

namespace App\Payments;

/**
 * Where a customer's payment page is up to, read from Stripe's API or a webhook the same way.
 */
final readonly class CheckoutSession
{
    /**
     * @param  'open'|'complete'|'expired'  $status
     * @param  array{line1: string|null, line2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string|null}|null  $shippingAddress
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $paymentStatus,
        public ?string $url = null,
        public ?string $orderPublicId = null,
        public ?string $paymentIntentId = null,
        public ?string $customerName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?array $shippingAddress = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $session  a Checkout Session object as Stripe sends it
     */
    public static function fromStripe(array $session): self
    {
        $customer = self::object($session, 'customer_details');
        $shipping = self::object(self::object($session, 'collected_information'), 'shipping_details');
        $address = self::object($shipping, 'address');
        $paymentIntent = $session['payment_intent'] ?? null;

        return new self(
            id: self::text($session, 'id') ?? '',
            status: match (self::text($session, 'status')) {
                'complete' => 'complete',
                'expired' => 'expired',
                default => 'open',
            },
            paymentStatus: self::text($session, 'payment_status') ?? 'unpaid',
            url: self::text($session, 'url'),
            orderPublicId: self::text(self::object($session, 'metadata'), 'order_id') ?? self::text($session, 'client_reference_id'),
            paymentIntentId: is_string($paymentIntent) ? $paymentIntent : (is_array($paymentIntent) ? self::text($paymentIntent, 'id') : null),
            customerName: self::text($customer, 'name') ?? self::text($shipping, 'name'),
            email: self::text($customer, 'email'),
            phone: self::text($customer, 'phone'),
            shippingAddress: $address === [] ? null : [
                'line1' => self::text($address, 'line1'),
                'line2' => self::text($address, 'line2'),
                'city' => self::text($address, 'city'),
                'state' => self::text($address, 'state'),
                'postal_code' => self::text($address, 'postal_code'),
                'country' => self::text($address, 'country'),
            ],
        );
    }

    /**
     * Finished and paid for (or free).
     */
    public function isPaid(): bool
    {
        return $this->status === 'complete' && in_array($this->paymentStatus, ['paid', 'no_payment_required'], true);
    }

    /**
     * Finished with a payment that takes days to clear, like a bank debit.
     */
    public function isAwaitingPayment(): bool
    {
        return $this->status === 'complete' && $this->paymentStatus === 'unpaid';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function object(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function text(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
    }
}
