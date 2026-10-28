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
use App\Sms\BroadcastPreview;
use App\Sms\ConversationList;
use App\Sms\SmsText;
use App\Support\Money;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
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
    }

    /**
     * Called as the page refreshes itself, so texts that arrive while a conversation is open don't stay unread.
     */
    public function refreshOpen(): void
    {
        if ($this->phone !== null) {
            ConversationList::markRead($this->phone);
        }
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
    }

    public function clearFollowUp(): void
    {
        if ($this->phone !== null) {
            ConversationState::clearFollowUp($this->phone);
        }
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
}
