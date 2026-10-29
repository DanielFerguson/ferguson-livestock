<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('business-rules')]
#[Uri('ferguson://business-rules')]
#[MimeType('text/markdown')]
#[Description('How drops, stock, orders and checkout work, so you can answer questions and make changes without guessing.')]
final class BusinessRulesResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text(<<<'MD'
        # How Ferguson Livestock works

        ## Drops
        - A drop is a timed sale. It has an opening time, an optional closing time, and a list of items (a product with a price, a Stripe price id and a stock quantity).
        - Status is derived, never stored: **Draft** (not published) → **Announced** (draft whose date is on the website) → **Scheduled** (published, before it opens) → **Live** → **Sold out** (open, nothing left) → **Closed**.
        - "Going live" = publishing. The drop opens by itself at its opening time.
        - Two published drops can't overlap. A drop also ends when the next published drop opens.
        - A pre-flight check runs automatically 10 minutes before opening (and on demand). It checks stock, delivery days, Stripe prices and webhook, and the site's infrastructure.

        ## Stock
        - `quantity` is the total for sale; `available` is what's left after sales and checkout holds. Units are held while a customer is at Stripe's payment page (15 minutes by default; the shop closes the page itself when that runs out).
        - You can raise or lower `quantity`, but never below the units already held or sold.
        - A **bundle** is a product sold as several units of another product: the 10kg beef box is 2 × the 5kg box, a 5kg mince pack is 10 × the 500g mince. Selling one uses up that many of the component's stock, so they can never be oversold together. A bundle has no quantity of its own: set the component's quantity, and the component must be on the same drop. A bundle keeps the rules of its type (a bundled box is still a box; a mince pack is an extra).
        - Delivery is a product with no stock.

        ## Orders
        - Pending (at checkout, stock held) → Processing (slow payment such as a bank debit) → Paid → Fulfilled. Expired and Failed release the stock.
        - Customers see the reference `FL-` plus the last six characters of the order's public id.
        - Refunds are made in the Stripe dashboard; the site records them when Stripe says so and does not restock.

        ## Money and time
        - Money is integer cents in AUD. Times are Australia/Melbourne.
        MD);
    }
}
