<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('new-drop-setup')]
#[Description('Walk through setting up a new drop: dates, products, Stripe prices, stock, delivery days, pre-flight, then publishing.')]
final class NewDropSetupPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $notes = $request->get('notes');

        return Response::text(<<<TEXT
        Help me set up a new drop. Work through this in order, asking me for anything you can't find out yourself.

        1. get-overview and list-drops: is there already a draft or upcoming drop I should reuse or copy (duplicate-drop)?
        2. Ask for the name and opening time (and closing time if any). Remember times are Melbourne time.
        3. list-products and list-stripe-prices: match each product to its Stripe price, and ask me how many of each I'm selling. Delivery is a product with a Stripe price and no stock.
        4. Ask for the delivery days.
        5. create-drop (or update-drop and set-drop-item on a copy). Show me the result with each item's price and stock.
        6. run-drop-preflight and fix or explain anything it finds.
        7. When I'm happy: prepare-publish-drop, show me what will happen, and only publish-drop after I say yes.
        8. Offer to draft the announcement text (draft-drop-announcement) and send me a test.

        Anything I've already told you: {$this->notes($notes)}
        TEXT);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(name: 'notes', description: 'Anything already decided, e.g. "opens Saturday 14 Nov 9am, 20 boxes".', required: false),
        ];
    }

    private function notes(mixed $notes): string
    {
        return is_string($notes) && trim($notes) !== '' ? trim($notes) : 'nothing yet.';
    }
}
