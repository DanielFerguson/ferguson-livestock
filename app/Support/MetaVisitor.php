<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The parts of a visitor's browser Meta uses to match an event to the ad they came from: their address and browser,
 * and the two cookies Meta's pixel sets (_fbp for the browser, _fbc for the ad click).
 */
final readonly class MetaVisitor
{
    public const string BROWSER_COOKIE = '_fbp';

    public const string CLICK_COOKIE = '_fbc';

    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $fbp = null,
        public ?string $fbc = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            ip: $request->ip(),
            userAgent: self::text($request->userAgent()),
            fbp: self::text($request->cookie(self::BROWSER_COOKIE)),
            fbc: self::text($request->cookie(self::CLICK_COOKIE)),
        );
    }

    /**
     * @param  array{ip?: string|null, user_agent?: string|null, fbp?: string|null, fbc?: string|null}|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        return new self($stored['ip'] ?? null, $stored['user_agent'] ?? null, $stored['fbp'] ?? null, $stored['fbc'] ?? null);
    }

    /**
     * @return array{ip: string|null, user_agent: string|null, fbp: string|null, fbc: string|null}
     */
    public function toArray(): array
    {
        return ['ip' => $this->ip, 'user_agent' => $this->userAgent, 'fbp' => $this->fbp, 'fbc' => $this->fbc];
    }

    /**
     * Meta's names for these, as they go in an event's user_data. Missing ones are left out.
     *
     * @return array<string, string>
     */
    public function userData(): array
    {
        return array_filter([
            'client_ip_address' => $this->ip,
            'client_user_agent' => $this->userAgent,
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * Cookies and headers come from the browser, so anything that isn't a short string is dropped.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 512 ? $value : null;
    }
}
