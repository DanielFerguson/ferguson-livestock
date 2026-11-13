<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Exceptions\SmsNotSent;
use App\Jobs\Concerns\WaitsUntilDue;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Sms\OrderConfirmationText;
use App\Sms\QuietHours;
use App\Sms\SmsGateway;
use App\Support\AustralianMobile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Texts a customer what they ordered and when it arrives or can be collected (see OrderConfirmationText).
 *
 * The orders are claimed before the text goes, so a retry, a second copy of this job or the admin sending the drop's
 * texts again never texts anyone twice. A text due in quiet hours waits until 8am, unless the owner chose to send
 * it then. The sync queue never sends it (see WaitsUntilDue), so a machine without a worker can't text anyone.
 */
class SendOrderConfirmationText implements ShouldQueue
{
    use Queueable, WaitsUntilDue;

    /**
     * An unexpected error, like the database dropping out, is retried: orders already claimed aren't texted again.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  list<int>  $orderIds  one person's orders, all going out the same way
     * @param  bool  $evenInQuietHours  the owner chose to send now, though it's between 8pm and 8am
     */
    public function __construct(public array $orderIds, public bool $evenInQuietHours = false) {}

    public function handle(SmsGateway $sms): void
    {
        if ($this->waitingUntil($this->evenInQuietHours ? now() : QuietHours::nextSendingTime(now()))) {
            return;
        }

        $orders = Order::query()->with(['items.product', 'drop'])->whereKey($this->orderIds)->orderBy('id')->get();
        $phone = AustralianMobile::toE164((string) $orders->first()?->phone);

        if ($phone === null) {
            return;
        }

        $subscriber = Subscriber::query()->firstWhere('phone', $phone);

        if ($subscriber instanceof Subscriber && ! $subscriber->isSubscribed()) {
            return;
        }

        $claimed = $this->claim(array_values(array_map(fn (Order $order): int => $order->id, $orders->all())));
        $toText = array_values(array_filter($orders->all(), fn (Order $order): bool => in_array($order->id, $claimed, true)));

        if ($toText === []) {
            return;
        }

        $text = OrderConfirmationText::for($toText);

        $message = SmsMessage::query()->create([
            'subscriber_id' => $subscriber?->id,
            'direction' => SmsDirection::Outbound,
            'to' => $phone,
            'from' => config()->string('services.twilio.from'),
            'body' => $text->text,
            'status' => SmsStatus::Sending,
        ]);

        try {
            $sent = $sms->send($phone, $text->text);
        } catch (SmsNotSent $exception) {
            $message->update(['status' => SmsStatus::Failed, 'error_code' => $exception->errorCode]);

            if ($exception->recipientOptedOut()) {
                $subscriber?->optOut();
            }

            return;
        }

        $message->update(['status' => SmsStatus::Sent, 'twilio_sid' => $sent->sid, 'segments' => $sent->segments, 'sent_at' => now()]);
    }

    /**
     * Mark the orders still waiting for their text as texted, in one transaction, so nobody else sends it.
     *
     * @param  list<int>  $orderIds
     * @return list<int> the orders this job now texts about
     */
    private function claim(array $orderIds): array
    {
        return DB::transaction(function () use ($orderIds): array {
            /** @var list<int> $waiting */
            $waiting = Order::query()
                ->whereKey($orderIds)
                ->whereNull('confirmation_texted_at')
                ->whereIn('status', [OrderStatus::Paid, OrderStatus::CashOwed])
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            Order::query()->whereKey($waiting)->update(['confirmation_texted_at' => now()]);

            return $waiting;
        });
    }
}
