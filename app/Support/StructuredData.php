<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * schema.org JSON-LD for the public pages. The farm is described once, as a LocalBusiness
 * (a kind of Organization), and every other node refers to it by @id.
 */
final class StructuredData
{
    /**
     * @return array<string, mixed>
     */
    public static function webPage(string $path, string $title, string $description, string $type = 'WebPage'): array
    {
        $url = self::url($path);

        return [
            '@context' => 'https://schema.org',
            '@type' => $type,
            '@id' => "{$url}#webpage",
            'url' => $url,
            'name' => $title,
            'description' => $description,
            'isPartOf' => ['@id' => self::url('/').'#website'],
            'about' => ['@id' => self::businessId()],
            'primaryImageOfPage' => [
                '@type' => 'ImageObject',
                'url' => self::url(config()->string('shop.og_image.path')),
            ],
            'inLanguage' => 'en-AU',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function home(string $title, string $description): array
    {
        $home = self::url('/');
        $image = self::url(config()->string('shop.og_image.path'));
        $facebook = config()->string('shop.social.facebook');
        $telephone = config()->string('shop.phone.international');

        $homePage = self::webPage('/', $title, $description);
        unset($homePage['@context']);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'LocalBusiness',
                    '@id' => self::businessId(),
                    'name' => config()->string('shop.name'),
                    'description' => $description,
                    'url' => $home,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => self::url('/android-chrome-512x512.png'),
                        'width' => 512,
                        'height' => 512,
                    ],
                    'image' => $image,
                    'telephone' => $telephone,
                    'email' => config()->string('shop.email'),
                    'sameAs' => [$facebook],
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => config()->string('shop.location.locality'),
                        'addressRegion' => config()->string('shop.location.region'),
                        'postalCode' => config()->string('shop.location.postal_code'),
                        'addressCountry' => config()->string('shop.location.country'),
                    ],
                    'areaServed' => [
                        ['@type' => 'City', 'name' => config()->string('shop.location.nearby_city')],
                        ['@type' => 'Place', 'name' => config()->string('shop.location.locality')],
                    ],
                    'contactPoint' => [
                        '@type' => 'ContactPoint',
                        'telephone' => $telephone,
                        'contactType' => 'sales',
                        'areaServed' => 'AU-VIC',
                        'availableLanguage' => 'en-AU',
                    ],
                    'priceRange' => '$$',
                    'currenciesAccepted' => 'AUD',
                    'paymentAccepted' => 'Credit Card',
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => "{$home}#website",
                    'url' => $home,
                    'name' => config()->string('shop.name'),
                    'description' => $description,
                    'publisher' => ['@id' => self::businessId()],
                    'inLanguage' => 'en-AU',
                ],
                $homePage,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function beefBoxes(string $title, string $description, Catalogue $catalogue): array
    {
        $pageUrl = self::url('/beef-boxes');
        $image = url(Vite::asset('resources/images/generated/beef-box-720.webp'));

        return [
            self::webPage('/beef-boxes', $title, $description),
            [
                '@context' => 'https://schema.org',
                '@graph' => $catalogue->boxes()->map(fn (Product $box) => [
                    '@type' => 'Product',
                    '@id' => "{$pageUrl}#{$box->slug}",
                    'name' => $box->name,
                    'description' => $box->description,
                    'image' => $image,
                    'brand' => ['@type' => 'Brand', 'name' => config()->string('shop.name')],
                    'offers' => [
                        '@type' => 'Offer',
                        'url' => self::url('/order'),
                        'priceCurrency' => 'AUD',
                        'price' => number_format($box->price / 100, 2, '.', ''),
                        'seller' => ['@id' => self::businessId()],
                    ],
                ])->all(),
            ],
        ];
    }

    /**
     * @param  list<array{question: string, answer: string}>  $faqs
     * @return array<string, mixed>
     */
    public static function faq(array $faqs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq) => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
            ], $faqs),
        ];
    }

    private static function businessId(): string
    {
        return self::url('/').'#organization';
    }

    private static function url(string $path): string
    {
        $base = rtrim(config()->string('shop.url'), '/');

        return $path === '/' ? "{$base}/" : $base.'/'.ltrim($path, '/');
    }
}
