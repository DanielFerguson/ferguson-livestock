<?php

namespace App\Http\Requests;

use App\Support\AustralianMobile;
use App\Support\Turnstile;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class JoinWaitlistRequest extends FormRequest
{
    /**
     * Keep the form's errors separate from any other form on the page.
     *
     * @var string
     */
    protected $errorBag = 'waitlist';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || AustralianMobile::toE164($value) === null) {
                    $fail('Please enter an Australian mobile number, like 0412 345 678. We send drop alerts by text.');
                }
            }],
            'postcode' => ['required', 'digits:4'],
            'sms_consent' => ['accepted'],
            // A hidden field only bots fill in; the controller discards those sign-ups.
            'website' => ['nullable', 'string'],
            Turnstile::FIELD => Turnstile::isEnabled() ? ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(Turnstile::class)->passes($value, $this->ip())) {
                    $fail(Turnstile::FAILED_MESSAGE);
                }
            }] : [],
        ];
    }

    /**
     * Plain-English messages for people who aren't used to online forms.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Please tell us your first name.',
            'first_name.max' => 'Please keep your first name under 60 characters.',
            'phone.required' => 'Please enter your mobile number so we can text you.',
            'postcode.required' => 'Please enter your four-digit postcode.',
            'postcode.digits' => 'Please enter your four-digit postcode.',
            'sms_consent.accepted' => 'Please tick the box so we can text you when the next drop opens.',
            Turnstile::FIELD.'.required' => Turnstile::FAILED_MESSAGE,
        ];
    }

    public function firstName(): string
    {
        return $this->string('first_name')->trim()->value();
    }

    public function mobile(): string
    {
        return (string) AustralianMobile::toE164($this->string('phone')->value());
    }

    /**
     * The URL of the wait-list form on the page someone submitted from.
     */
    public static function formUrl(string $page): string
    {
        $hasPath = (parse_url($page, PHP_URL_PATH) ?? '') !== '';

        return ($hasPath ? $page : "{$page}/").'#waitlist';
    }

    /**
     * Send people back to the form itself, not the top of the page.
     */
    protected function getRedirectUrl(): string
    {
        return self::formUrl(parent::getRedirectUrl());
    }
}
