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
        $fiveKg = $this->catalogue->find('beef-box-5kg');
        $tenKg = $this->catalogue->find('beef-box-10kg');
        $deliveryArea = config()->string('shop.delivery.area_name');

        return [
            [
                'question' => 'How does ordering work?',
                'answer' => 'When a drop opens, choose a 5kg or 10kg box, add any available individual cuts, select delivery or farm pickup, and complete payment securely through Stripe. If boxes are sold out, you can still order any individual cuts showing as available.',
            ],
            [
                'question' => 'How much do the beef boxes cost?',
                'answer' => sprintf(
                    '5kg beef boxes are %s (%s), and 10kg beef boxes are %s (%s).',
                    Money::format($fiveKg->price),
                    Money::perKg($fiveKg->box->perKgPrice ?? 0),
                    Money::format($tenKg->price),
                    Money::perKg($tenKg->box->perKgPrice ?? 0),
                ),
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
                    Money::format($this->catalogue->deliveryFee()),
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
}
