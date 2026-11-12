<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\RecordCashOrder;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\CannotRecordOrder;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Drop;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a cash order for family or friends. The rules and the stock live in RecordCashOrder, which Claude's
 * record-cash-order tool uses too.
 *
 * @property Order $record
 */
class CreateCashOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Record cash order';

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    protected function handleRecordCreation(array $data): Model
    {
        $text = fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? $data[$key] : null;
        $deliveryDay = $text('delivery_day');

        try {
            return app(RecordCashOrder::class)(
                drop: Drop::query()->findOrFail(is_numeric($data['drop_id'] ?? null) ? (int) $data['drop_id'] : 0),
                quantities: self::quantities($data['items'] ?? []),
                deliveryMethod: $data['delivery_method'] instanceof DeliveryMethod ? $data['delivery_method'] : DeliveryMethod::from($text('delivery_method') ?? ''),
                deliveryDay: $deliveryDay === null ? null : CarbonImmutable::parse($deliveryDay),
                customerName: $text('customer_name') ?? '',
                phone: $text('phone'),
                address: ['line1' => $text('street'), 'city' => $text('suburb'), 'postal_code' => $text('postcode')],
                paid: ($data['paid'] ?? false) === true,
            );
        } catch (CannotRecordOrder $exception) {
            Notification::make()->title('Order not recorded')->body($exception->getMessage())->danger()->send();

            throw new Halt($exception->getMessage(), $exception->getCode(), previous: $exception);
        }
    }

    protected function getCreatedNotificationTitle(): string
    {
        return $this->record->status === OrderStatus::CashOwed ? 'Cash order recorded. It’s waiting on the cash.' : 'Cash order recorded as paid';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Record order');
    }

    /**
     * The repeater's lines as drop item ID => quantity.
     *
     * @return array<int, int>
     */
    private static function quantities(mixed $lines): array
    {
        $quantities = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line) && is_numeric($line['drop_item_id'] ?? null) && is_numeric($line['quantity'] ?? null)) {
                $quantities[(int) $line['drop_item_id']] = ($quantities[(int) $line['drop_item_id']] ?? 0) + (int) $line['quantity'];
            }
        }

        return $quantities;
    }
}
