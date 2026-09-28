<?php

namespace App\Filament\Imports;

use App\Models\Subscriber;
use App\Support\AustralianMobile;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

/**
 * Moves the SMS list over from Klaviyo's CSV export, keeping the date each person agreed to texts.
 *
 * Rows without SMS consent are skipped, and so is anyone already on the list: their record, including an
 * opt-out, is newer than Klaviyo's.
 */
class SubscriberImporter extends Importer
{
    protected static ?string $model = Subscriber::class;

    /**
     * How Klaviyo's "SMS Consent" column can say someone agreed. Anything else there means they didn't.
     */
    private const array CONSENTED = ['SUBSCRIBED', 'TRUE', 'YES', 'Y', '1'];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('phone')
                ->label('Mobile number')
                ->requiredMapping()
                ->guess(['Phone Number', 'Phone', 'Mobile', 'phone_number'])
                ->castStateUsing(fn (?string $state): ?string => $state === null ? null : (AustralianMobile::toE164($state) ?? $state))
                ->rules(['required', function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || AustralianMobile::toE164($value) === null) {
                        $fail('This isn’t an Australian mobile number.');
                    }
                }]),
            ImportColumn::make('first_name')
                ->guess(['First Name', 'first_name'])
                ->castStateUsing(fn (?string $state): ?string => self::blankToNull($state) === null ? null : mb_substr(trim((string) $state), 0, 60)),
            ImportColumn::make('postcode')
                ->guess(['Zip Code', 'Postal Code', 'Postcode', 'zip'])
                ->castStateUsing(fn (?string $state): ?string => preg_match('/^\d{4}$/', trim((string) $state)) === 1 ? trim((string) $state) : null),
            ImportColumn::make('consented_at')
                ->label('SMS consent date')
                ->requiredMapping()
                ->guess(['SMS Consent Timestamp', 'SMS Consent Date', 'sms_consent_timestamp'])
                ->castStateUsing(fn (?string $state): CarbonImmutable|string|null => self::date($state))
                ->rules(['nullable', 'date']),
            ImportColumn::make('sms_consent')
                ->label('SMS consent status')
                ->guess(['SMS Consent', 'SMS Consent Status', 'sms_consent'])
                ->fillRecordUsing(function (): void {}),
        ];
    }

    public function resolveRecord(): ?Subscriber
    {
        $phone = $this->data['phone'] ?? null;

        if (! $this->hasSmsConsent() || (is_string($phone) && Subscriber::where('phone', $phone)->exists())) {
            return null;
        }

        return new Subscriber([
            'consent_source' => 'klaviyo import',
            'consent_wording' => Subscriber::KLAVIYO_CONSENT_WORDING,
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Checked '.Number::format($import->total_rows).' rows from Klaviyo. People without SMS consent, and anyone already on the list, were skipped.';

        $failed = $import->getFailedRowsCount();

        if ($failed > 0) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' couldn’t be imported. Download them to see why.';
        }

        return $body;
    }

    private function hasSmsConsent(): bool
    {
        $status = self::blankToNull(is_string($this->data['sms_consent'] ?? null) ? $this->data['sms_consent'] : null);

        return ($this->data['consented_at'] ?? null) !== null
            && ($status === null || in_array(strtoupper($status), self::CONSENTED, true));
    }

    /**
     * A date Klaviyo wrote, or the text as written so validation can reject it.
     */
    private static function date(?string $state): CarbonImmutable|string|null
    {
        $state = self::blankToNull($state);

        try {
            return $state === null ? null : CarbonImmutable::parse($state);
        } catch (InvalidFormatException) {
            return $state;
        }
    }

    private static function blankToNull(?string $state): ?string
    {
        return $state === null || trim($state) === '' ? null : trim($state);
    }
}
