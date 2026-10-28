<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Rules\ExistingLocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Throwable;

/**
 * A tool's validated arguments, read back with their types so tools never handle `mixed`.
 */
final readonly class Arguments
{
    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private array $values) {}

    /**
     * Validate the request. A failure reaches the AI client as an error saying what to fix.
     *
     * @param  array<string, list<string|Enum|object>>  $rules
     * @param  array<string, string>  $messages
     */
    public static function validate(Request $request, array $rules, array $messages = []): self
    {
        return new self($request->validate($rules, $messages));
    }

    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function requireString(string $key): string
    {
        return $this->string($key) ?? '';
    }

    public function integer(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    public function requireInteger(string $key): int
    {
        return $this->integer($key) ?? 0;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return list<string>|null
     */
    public function strings(string $key): ?array
    {
        $value = $this->values[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : null;
    }

    /**
     * A time as an absolute instant. ISO-8601 with an offset is taken as given; without one it is Melbourne
     * wall-clock time, and a time that daylight saving skips or repeats is refused.
     *
     * @throws ValidationException
     */
    public function dateTime(string $key): ?CarbonImmutable
    {
        $value = $this->string($key);

        if ($value === null) {
            return null;
        }

        $timezone = config()->string('shop.timezone');
        $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value) === 1;

        if (! $hasOffset) {
            Validator::make([$key => $value], [$key => [new ExistingLocalTime($timezone)]])->validate();
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages([$key => "\"{$value}\" is not a date and time. Use ISO-8601, e.g. 2026-11-14T09:00:00."]);
        }
    }
}
