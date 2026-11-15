<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\SendReply;
use App\Enums\ConversationFilter;
use App\Enums\SmsStatus;
use App\Exceptions\CannotReply;
use App\Models\ConversationState;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\AdminPush;
use App\Sms\BroadcastPreview;
use App\Sms\ConversationList;
use App\Sms\SmsText;
use App\Support\Money;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;
use UnitEnum;

/**
 * Texts from people who've messaged the shop's number, as conversations you can answer.
 */
class Conversations extends Page
{
    protected string $view = 'filament.pages.conversations';

    protected static ?string $slug = 'conversations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Texts';

    protected static ?int $navigationSort = 2;

    #[Url]
    public ?string $phone = null;

    public string $search = '';

    #[Url]
    public string $filter = 'all';

    public string $draft = '';

    public static function getNavigationBadge(): ?string
    {
        $unread = ConversationList::attentionCount();

        return $unread > 0 ? (string) $unread : null;
    }

    public function mount(): void
    {
        if ($this->phone !== null) {
            ConversationList::markRead($this->phone);
        }
    }

    public function open(string $phone): void
    {
        $this->phone = $phone;
        $this->draft = '';
        ConversationList::markRead($phone);
        $this->updateBadge();
    }

    /**
     * Called as the page refreshes itself, so texts that arrive while a conversation is open don't stay unread.
     */
    public function refreshOpen(): void
    {
        if ($this->phone !== null) {
            ConversationList::markRead($this->phone);
        }

        $this->updateBadge();
    }

    public function send(SendReply $reply): void
    {
        if ($this->phone === null) {
            return;
        }

        try {
            $message = $reply($this->phone, $this->draft);
        } catch (CannotReply $exception) {
            Notification::make()->title($exception->getMessage())->warning()->send();

            return;
        }

        if ($message->status === SmsStatus::Failed) {
            Notification::make()->title('The text didn’t send')->body('Twilio refused it'.($message->error_code !== null ? " (error {$message->error_code})" : '').'.')->danger()->send();

            return;
        }

        $this->draft = '';
    }

    /**
     * Flag the open conversation to come back to. It counts as unread until it's ticked off as done.
     */
    public function followUp(): void
    {
        if ($this->phone !== null) {
            ConversationState::followUp($this->phone);
        }

        $this->updateBadge();
    }

    public function clearFollowUp(): void
    {
        if ($this->phone !== null) {
            ConversationState::clearFollowUp($this->phone);
        }

        $this->updateBadge();
    }

    /**
     * Tick the open conversation off. A new text from them reopens it.
     */
    public function done(): void
    {
        if ($this->phone !== null) {
            ConversationState::markDone($this->phone);
            ConversationList::markRead($this->phone);
        }

        $this->updateBadge();
    }

    /**
     * The key a phone signs up for pushes with. Null until both of the site's push keys are set.
     */
    public static function pushPublicKey(): ?string
    {
        return AdminPush::isSetUp() ? config()->string('webpush.vapid.public_key') : null;
    }

    /**
     * Sign this phone up for a push with each new text. The browser hands over where to send them and the keys to
     * encrypt them with. Saving the same phone again refreshes its keys.
     *
     * The keys must be the shape a browser makes (a P-256 public key and a 16-byte secret, base64url): pushes are
     * encrypted together before any is sent, so one malformed sign-up would stop every phone getting them. A refused
     * sign-up is logged and returns false.
     *
     * @param  array<mixed>  $subscription
     */
    #[Renderless]
    public function savePushSubscription(array $subscription): bool
    {
        $validator = Validator::make($subscription, [
            'endpoint' => ['required', 'string', 'url:https', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
            'keys.p256dh' => ['required', 'string', 'regex:/^B[A-Za-z0-9_-]{86}=?\z/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}(==)?\z/'],
            'contentEncoding' => ['required', Rule::enum(ContentEncoding::class)],
        ]);

        if ($validator->fails()) {
            $endpoint = $subscription['endpoint'] ?? null;
            $host = is_string($endpoint) ? parse_url($endpoint, PHP_URL_HOST) : null;

            Log::warning('A phone’s push sign-up was refused.', [
                'host' => is_string($host) ? $host : null,
                'errors' => $validator->errors()->all(),
            ]);

            return false;
        }

        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string} $valid */
        $valid = $validator->validated();

        $this->admin()->updatePushSubscription($valid['endpoint'], $valid['keys']['p256dh'], $valid['keys']['auth'], $valid['contentEncoding']);

        return true;
    }

    #[Renderless]
    public function removePushSubscription(string $endpoint): void
    {
        $this->admin()->deletePushSubscription($endpoint);
    }

    /**
     * The "Send a test" button, which is how the owner checks notifications work, so it says what Apple answered. A
     * refused test still reaches WarnWhenPushesStop through the push package, like any other push.
     */
    #[Renderless]
    public function sendTestPush(WebPushChannel $channel): void
    {
        $reports = $channel->send($this->admin(), AdminPush::test());

        if ($reports === []) {
            Notification::make()->title('No phone is signed up')->body('Open Ferguson Livestock from your phone’s home screen and tap Turn on notifications in Conversations.')->warning()->send();

            return;
        }

        $refused = array_find($reports, fn (MessageSentReport $report): bool => ! $report->isSuccess());

        if ($refused instanceof MessageSentReport) {
            $status = $refused->getResponse()?->getStatusCode();

            Notification::make()
                ->title('Apple didn’t take the test')
                ->body($status !== null ? "Apple answered {$status} ({$refused->getReason()})." : "Apple didn’t answer: {$refused->getReason()}")
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Test sent')->body('It should show up on your phone in a few seconds. If it doesn’t, turn notifications off and on again.')->success()->send();
    }

    /**
     * @return Collection<int, array{phone: string, name: ?string, last: SmsMessage, unread: int, optedOut: bool, followUp: bool, needsReply: bool, attention: bool}>
     */
    public function conversations(): Collection
    {
        return ConversationList::summaries($this->search, ConversationFilter::tryFrom($this->filter) ?? ConversationFilter::All);
    }

    /**
     * The filter choices, each with how many conversations it would show.
     *
     * @return array<string, string>
     */
    public function filterOptions(): array
    {
        $counts = ConversationList::counts($this->search);

        return collect(ConversationFilter::cases())
            ->mapWithKeys(fn (ConversationFilter $filter): array => [$filter->value => "{$filter->getLabel()} ({$counts[$filter->value]})"])
            ->all();
    }

    /**
     * Where the open conversation stands: flagged, waiting for a reply, or neither.
     *
     * @return array{followUp: bool, needsReply: bool, attention: bool}|null
     */
    public function standing(): ?array
    {
        $summary = $this->phone === null ? null : ConversationList::summary($this->phone);

        return $summary === null ? null : ['followUp' => $summary['followUp'], 'needsReply' => $summary['needsReply'], 'attention' => $summary['attention']];
    }

    /**
     * @return Collection<int, SmsMessage>
     */
    public function thread(string $phone): Collection
    {
        return ConversationList::thread($phone);
    }

    public function subscriber(): ?Subscriber
    {
        return $this->phone === null ? null : Subscriber::query()->firstWhere('phone', $this->phone);
    }

    public function hasOptedOut(): bool
    {
        return $this->subscriber()?->isSubscribed() === false;
    }

    /**
     * "162 characters, so 2 texts each, about $0.16"
     */
    public function draftHint(): ?string
    {
        if (trim($this->draft) === '') {
            return null;
        }

        $preview = new BroadcastPreview(new SmsText(SmsText::tidy($this->draft)), 1);

        return $preview->length().', about '.Money::format($preview->estimatedCost());
    }

    private function admin(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    /**
     * Tell the installed app how many conversations need a look, for the badge on its icon.
     */
    private function updateBadge(): void
    {
        $this->dispatch('attention-count', count: ConversationList::attentionCount());
    }
}
