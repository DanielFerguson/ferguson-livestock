# Free items on orders

The owner sometimes hands out sample packs (a Sausage Pack, a 500g Mince) to customers when they pick up. These
should come out of the drop's stock and be recorded against the customer's order, so inventory stays right.

## Approach

Add free items to an existing order as extra lines, priced at $0 and flagged `free`, that take stock from the drop
like any other line. Rejected: a separate $0 order per gift (clutters orders and the run sheet) and lowering the
drop item's stock by hand (loses who got what).

## Rules

- Allowed on Paid, Fulfilled and Cash owed orders (their stock is held). Not on Pending, Processing, Expired or
  Failed orders.
- Allowed whether the drop is live or closed: pickups happen after a drop closes.
- Any item on the order's drop except the delivery fee. Bundles draw on their component's stock as usual. Per-order
  limits don't apply.
- Stock is taken with the same locked, all-or-nothing hold checkout uses. When there isn't enough: "Only 1 ×
  Sausage Pack (6) left. If you’ve got more on hand, raise its stock on the drop first."
- The order total doesn't change, so refunds, drop revenue and Meta revenue are unaffected.
- A free line can be removed (a mistake at pickup); its stock goes back on sale. Only free lines can be removed.
- Cancelling a cash order releases everything on it, free lines included, as today.
- Nothing is texted or emailed.

## Data

`order_items.free` boolean, default false. Free lines have `unit_price` 0.

## Pieces

- `StockLedger::addToOrder(Order, quantities, attributes)`: locks and takes stock for new lines on an existing
  order, reusing `lines()` and `takeStock()`; refuses an order whose stock has been released.
- `StockLedger::takeBackLine(OrderItem)`: deletes a line and puts its stock back, in one transaction, unless the
  order's stock was already released.
- `App\Actions\Orders\GiveFreeItems` and `App\Actions\Orders\TakeBackFreeItem`: validate status, item and stock,
  throwing `CannotChangeOrder` with owner-facing messages.
- Admin order page: "Add free item" header action (item + quantity) and "Remove free item" (visible when the order
  has a free line). Items section shows "Free" for free lines.
- MCP: `give-free-items-tool` (order reference + items by product id) and `remove-free-item-tool`. Single-step,
  like `record-cash-order-tool`. `Presenter::order()` marks free lines.
- `DropSales::forItem()`: `sold` excludes free lines; new `given_away`. Shown on the current-drop widget and in
  `get-drop-tool`.

## Testing

Feature tests: giving free items (stock taken, total unchanged, bundles), running short, refused statuses and the
delivery fee, closed drops allowed, removing a free line (stock back, paid lines refused), cancelling a cash order
releasing free stock, the `sold`/`given_away` split, the admin actions and both MCP tools.
