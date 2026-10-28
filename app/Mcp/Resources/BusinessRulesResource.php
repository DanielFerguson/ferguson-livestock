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
        - `quantity` is the total for sale; `available` is what's left after sales and checkout holds. Units are held while a customer is at Stripe's payment page (about 30 minutes).
        - You can raise or lower `quantity`, but never below the units already held or sold.
        - A product can borrow another product's stock (the 10kg box is two 5kg boxes). Those items have no quantity of their own.
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
