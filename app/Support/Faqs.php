<?php

namespace App\Support;

/**
 * The frequently asked questions shown on the homepage and /faq, and marked up as FAQPage on /faq.
 */
final readonly class Faqs
{
    public function __construct(private Catalogue $catalogue) {}

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function all(): array
    {
        $deliveryArea = config()->string('shop.delivery.area_name');
        $deliveryFee = $this->catalogue->deliveryFee();

        return [
            [
                'question' => 'How does ordering work?',
                'answer' => 'When a drop opens, choose a 5kg or 10kg box, add any available individual cuts, select delivery or farm pickup, and complete payment securely through Stripe. If boxes are sold out, you can still order any individual cuts showing as available.',
            ],
            [
                'question' => 'How much do the beef boxes cost?',
                'answer' => $this->boxPrices(),
            ],
            [
                'question' => 'What cuts are included?',
                'answer' => 'Each box contains a balanced mix of primary steaks, secondary and slow-cook cuts, roast, sausages and mince. Because each animal is different, the exact cuts and individual pack weights vary while the total box weight remains the same.',
            ],
            [
                'question' => 'Can I buy individual cuts?',
                'answer' => 'Yes. Mince, bones, sausages, rump, porterhouse and diced chuck may be offered individually when stock is available. The order page always shows the current range and quantity remaining.',
            ],
            [
                'question' => 'Where do you deliver?',
                'answer' => sprintf(
                    'We personally deliver across the %s. Delivery is %s per order. %s is also available %s.',
                    $deliveryArea,
                    $deliveryFee === null ? 'a flat fee' : Money::format($deliveryFee),
                    config()->string('shop.pickup.label'),
                    config()->string('shop.location.proximity'),
                ),
            ],
            [
                'question' => 'How is the beef raised?',
                'answer' => 'Our Murray Grey cattle are pasture-raised on our Snake Valley farm. If you would like to know more about how we raise them, ask us—we’re always happy to talk about our cattle.',
            ],
            [
                'question' => 'How is the beef processed and packed?',
                'answer' => 'The cattle are processed through a licensed local facility, and the cuts are professionally prepared and vacuum-sealed before collection or personal delivery.',
            ],
            [
                'question' => 'What happens when a drop sells out?',
                'answer' => 'Join the wait list and we will text you when the next beef-box drop opens. If individual cuts remain after the boxes sell out, they stay available on the order page while stock lasts.',
            ],
        ];
    }

    /**
     * Box prices from the featured drop, in the order the catalogue lists the boxes.
     */
    private function boxPrices(): string
    {
        $priced = $this->catalogue->boxes()->filter(fn (CatalogueEntry $box): bool => $box->price !== null && $box->box !== null);

        if ($priced->isEmpty()) {
            return 'Prices are set for each drop and shown on the order page before you pay.';
        }

        $prices = $priced->map(fn (CatalogueEntry $box): string => sprintf(
            '%dkg beef boxes are %s (%s)',
            $box->box->weightKg ?? 0,
            Money::format((int) $box->price),
            Money::perKg((int) $box->box?->perKgPrice),
        ))->values()->all();

        return count($prices) === 1
            ? "{$prices[0]}."
            : implode(', ', array_slice($prices, 0, -1)).', and '.end($prices).'.';
    }
}
