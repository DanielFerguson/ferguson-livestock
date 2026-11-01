<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The UTM tags on the link that brought a visitor to the site, e.g. ?utm_source=meta&utm_medium=paid&utm_campaign=…
 *
 * The site keeps them in a cookie for 90 days, so a visitor who lands from an ad and joins the wait list or orders
 * later is still traced back to it.
 */
final readonly class AdCampaign
{
    public const string COOKIE = 'fl_campaign';

    /**
     * The utm_source Meta ads are tagged with. Only this counts as a Meta ad in the shop's own results.
     */
    public const string META = 'meta';

    public const int DAYS = 90;

    private function __construct(
        public string $source,
        public ?string $medium,
        public ?string $campaign,
    ) {}

    /**
     * The tags on this request's own address, if it has a utm_source.
     */
    public static function fromQuery(Request $request): ?self
    {
        return self::from($request->query('utm_source'), $request->query('utm_medium'), $request->query('utm_campaign'));
    }

    /**
     * The tags remembered from the visitor's last tagged link, if any.
     */
    public static function fromCookie(Request $request): ?self
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value)) {
            return null;
        }

        parse_str($value, $tags);

        return self::from($tags['source'] ?? null, $tags['medium'] ?? null, $tags['campaign'] ?? null);
    }

    public function cookieValue(): string
    {
        return http_build_query(array_filter(['source' => $this->source, 'medium' => $this->medium, 'campaign' => $this->campaign], fn (?string $tag): bool => $tag !== null));
    }

    /**
     * @return array{utm_source: string, utm_medium: string|null, utm_campaign: string|null}
     */
    public function attributes(): array
    {
        return ['utm_source' => $this->source, 'utm_medium' => $this->medium, 'utm_campaign' => $this->campaign];
    }

    /**
     * Tags come from links anyone can write, so only short text is kept. Sources and mediums are lowercased, since
     * "Meta" and "meta" are the same source.
     */
    private static function from(mixed $source, mixed $medium, mixed $campaign): ?self
    {
        $source = self::tag($source, 100);

        if ($source === null) {
            return null;
        }

        $medium = self::tag($medium, 100);

        return new self(mb_strtolower($source), $medium === null ? null : mb_strtolower($medium), self::tag($campaign, 150));
    }

    private static function tag(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
