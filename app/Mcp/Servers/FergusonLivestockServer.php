<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DraftReplyPrompt;
use App\Mcp\Prompts\NewDropSetupPrompt;
use App\Mcp\Prompts\WeeklyBroadcastPrompt;
use App\Mcp\Resources\BusinessRulesResource;
use App\Mcp\Resources\CurrentDropResource;
use App\Mcp\Resources\SmsRulesResource;
use App\Mcp\Tools\Broadcasts\CountAudienceTool;
use App\Mcp\Tools\Broadcasts\DeleteBroadcastTool;
use App\Mcp\Tools\Broadcasts\DraftBroadcastTool;
use App\Mcp\Tools\Broadcasts\DraftDropAnnouncementTool;
use App\Mcp\Tools\Broadcasts\GetBroadcastTool;
use App\Mcp\Tools\Broadcasts\ListBroadcastsTool;
use App\Mcp\Tools\Broadcasts\PreviewBroadcastTool;
use App\Mcp\Tools\Broadcasts\ScheduleBroadcastTool;
use App\Mcp\Tools\Broadcasts\SendBroadcastNowTool;
use App\Mcp\Tools\Broadcasts\SendBroadcastTestTool;
use App\Mcp\Tools\Broadcasts\UnscheduleBroadcastTool;
use App\Mcp\Tools\Broadcasts\UpdateBroadcastTool;
use App\Mcp\Tools\Conversations\GetConversationTool;
use App\Mcp\Tools\Conversations\ListConversationsTool;
use App\Mcp\Tools\Conversations\PrepareReplyTool;
use App\Mcp\Tools\Conversations\SendReplyTool;
use App\Mcp\Tools\Conversations\UpdateConversationTool;
use App\Mcp\Tools\Drops\CloseDropNowTool;
use App\Mcp\Tools\Drops\CreateDropTool;
use App\Mcp\Tools\Drops\CreateProductTool;
use App\Mcp\Tools\Drops\DeleteDropTool;
use App\Mcp\Tools\Drops\DisableEarlyAccessTool;
use App\Mcp\Tools\Drops\DuplicateDropTool;
use App\Mcp\Tools\Drops\EnableEarlyAccessTool;
use App\Mcp\Tools\Drops\GetDropTool;
use App\Mcp\Tools\Drops\ListDropsTool;
use App\Mcp\Tools\Drops\ListProductsTool;
use App\Mcp\Tools\Drops\PrepareDeleteDropTool;
use App\Mcp\Tools\Drops\PreparePublishDropTool;
use App\Mcp\Tools\Drops\PublishDropTool;
use App\Mcp\Tools\Drops\RemoveDropItemTool;
use App\Mcp\Tools\Drops\RunDropPreflightTool;
use App\Mcp\Tools\Drops\SetDropItemTool;
use App\Mcp\Tools\Drops\UnpublishDropTool;
use App\Mcp\Tools\Drops\UpdateDropTool;
use App\Mcp\Tools\Drops\UpdateProductTool;
use App\Mcp\Tools\Orders\GetDeliveryRunTool;
use App\Mcp\Tools\Orders\GetOrderTool;
use App\Mcp\Tools\Orders\ListOrdersTool;
use App\Mcp\Tools\Orders\MarkOrderFulfilledTool;
use App\Mcp\Tools\Overview\GetOverviewTool;
use App\Mcp\Tools\Stripe\CheckStripeSetupTool;
use App\Mcp\Tools\Stripe\GetStripeEventTool;
use App\Mcp\Tools\Stripe\ListStripeEventsTool;
use App\Mcp\Tools\Stripe\ListStripePricesTool;
use App\Mcp\Tools\Stripe\RetryStripeEventTool;
use App\Mcp\Tools\Subscribers\GetSubscriberStatsTool;
use App\Mcp\Tools\Subscribers\ListSubscribersTool;
use App\Mcp\Tools\Subscribers\OptOutSubscriberTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Ferguson Livestock')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Runs Ferguson Livestock: a small beef business that sells boxes in timed "drops", takes payment through Stripe, and talks to its customers by SMS (Twilio). There is one admin, the owner.

Conventions
- Money is integer cents (AUD). Times are in Australia/Melbourne; send ISO-8601 and leave off the offset to mean Melbourne wall-clock time.
- A drop's status is derived: Draft (not published), Announced, Scheduled (published, before it opens), Live, SoldOut, Closed. "Going live" means publishing; the drop opens at its opens_at.
- Early access: a drop can have a secret link that lets family and close friends order before it is public (enable-early-access-tool). It works on an unpublished drop, uses the same stock, and keeps working once the drop opens. Keep the drop unpublished until the public should see it. The owner shares the link themselves; never text it to the wait list.
- Stock lives on a drop's items. Raising or lowering an item's quantity never takes it below what customers have already bought or are checking out.
- A bundle is a product sold as N units of another product (10kg box = 2 × 5kg box). It has no stock of its own: put its component on the drop with a quantity, then add the bundle with its own Stripe price.

Irreversible actions (texting subscribers, scheduling a broadcast, publishing or deleting a drop, sending a reply) are two steps. Call the matching prepare/preview tool, show the owner exactly what will happen (recipients, the message, cost, any warnings) and wait for their yes in the conversation. Only then call the action tool with the confirmation_token. Never reuse or invent a token; if anything is edited after the preview, prepare again.

Text messages
- Broadcasts get the "Ferguson Livestock:" prefix and the "Reply STOP to opt out" suffix added automatically. Replies get neither.
- Do not text between 8pm and 8am Melbourne time unless the owner has explicitly agreed to it.
- Never opt anyone back in; only the subscriber can, by texting START from their own phone.
- Write in the owner's plain, friendly, no-hype voice. Keep texts short and under 640 characters.
TEXT)]
class FergusonLivestockServer extends Server
{
    protected array $tools = [
        GetBroadcastTool::class,
        ListBroadcastsTool::class,
        GetConversationTool::class,
        ListConversationsTool::class,
        GetDropTool::class,
        ListDropsTool::class,
        ListProductsTool::class,
        GetDeliveryRunTool::class,
        GetOrderTool::class,
        ListOrdersTool::class,
        GetOverviewTool::class,
        CheckStripeSetupTool::class,
        GetStripeEventTool::class,
        ListStripeEventsTool::class,
        ListStripePricesTool::class,
        GetSubscriberStatsTool::class,
        ListSubscribersTool::class,
        PrepareReplyTool::class,
        SendReplyTool::class,
        UpdateConversationTool::class,
        CreateProductTool::class,
        UpdateProductTool::class,
        RetryStripeEventTool::class,
        OptOutSubscriberTool::class,
        CountAudienceTool::class,
        DeleteBroadcastTool::class,
        DraftBroadcastTool::class,
        DraftDropAnnouncementTool::class,
        PreviewBroadcastTool::class,
        ScheduleBroadcastTool::class,
        SendBroadcastNowTool::class,
        SendBroadcastTestTool::class,
        UnscheduleBroadcastTool::class,
        UpdateBroadcastTool::class,
        CloseDropNowTool::class,
        CreateDropTool::class,
        DeleteDropTool::class,
        DisableEarlyAccessTool::class,
        DuplicateDropTool::class,
        EnableEarlyAccessTool::class,
        PrepareDeleteDropTool::class,
        PreparePublishDropTool::class,
        PublishDropTool::class,
        RemoveDropItemTool::class,
        RunDropPreflightTool::class,
        SetDropItemTool::class,
        UnpublishDropTool::class,
        UpdateDropTool::class,
        MarkOrderFulfilledTool::class,
    ];

    protected array $resources = [
        BusinessRulesResource::class,
        SmsRulesResource::class,
        CurrentDropResource::class,
    ];

    protected array $prompts = [
        NewDropSetupPrompt::class,
        DraftReplyPrompt::class,
        WeeklyBroadcastPrompt::class,
    ];
}
