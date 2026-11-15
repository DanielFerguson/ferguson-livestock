# A customer's orders at handover

Some customers place more than one order on a drop: an extra box, something they forgot the first time. At pickup
the owner searches their name, taps one order, fulfils it and hands it over, and the rest gets left behind. On the
November Drop this happened with five customers (Mikele Linke's two orders were fulfilled 16 minutes apart; Aaron
Baker's three were handed over across two days).

The owner also works the orders resource on a phone, where the list and the order page carry more than is needed at
handover.

## Approach

Group a customer's orders on the existing order page: opening any of them shows everything they have on the drop,
and Mark fulfilled hands it all over. The list says "2 orders" under the name. Rejected: a separate pickup page with
one row per customer (a second place to fulfil from, and the orders list keeps the trap), and merging orders into one
(each has its own Stripe payment, refunds, customer page link and confirmation text).

## Which orders are one customer's

- Only orders on the same drop, and only placed ones: Paid, Processing, Fulfilled, Cash owed. Pending, Expired and
  Failed are ignored.
- Two orders are linked when they share a phone, an email (ignoring case) or a name (ignoring case and repeated or
  surrounding spaces). Blank fields never link.
- Links chain: an order linked to another by name and that one to a third by phone puts all three in one group.
  Aaron's cash order (name only) joins his online orders; Tanya's cash order ("Tanya") joins "Tanya Bransgrove" by
  phone.
- Two different people with the same name are grouped. Accepted: every order is listed by reference before anything
  is fulfilled.
- One class works the groups out, used by the list, the order page and the fulfil action, so they always agree.

## Orders list

- Tabs: All · To pick up · To deliver · Fulfilled · Cash owed · Not paid. To pick up and To deliver replace To
  fulfil; both cover Paid and Cash owed (`OrderStatus::toFulfil()`) split by delivery method, with a count badge
  like To fulfil had. The sidebar badge still counts everything to fulfil.
- To deliver sorts in delivery-run order: night (either-night orders last), then suburb.
- On phones only Order, Status and Customer show. Placed, Delivery and Total show from `md` up.
- Under the customer's name: "2 orders" (3, …) when they have more than one placed order on the drop, on every
  screen size. Delivery orders also show their night and suburb there, since the Delivery column is hidden on phones.

## Order page

Same layout on every screen size:

- A short summary at the top: reference, status, customer name, and Farm pickup or the delivery night.
- Items first. With one order the section is "Items". With more it's "Everything for {first name} · N orders", with a
  line per order giving its reference and status (this one first, marked as such), e.g. "FL-PXTAXX · Fulfilled Sat
  14 Nov" or "FL-598WC9 · Cash owed, collect $200", then its items. An order's delivery shows only when it differs
  from the one opened.
- Items show quantity and name, no line prices (the total is in the Order section). Free items are marked free. The
  delivery fee isn't listed.
- Order, Customer and Delivery become collapsed sections, like Stripe. Feedback is unchanged.

## Mark fulfilled

- Shown on any of the customer's orders while one of their orders is Paid or Cash owed, so it's there even on the
  order they already collected.
- One Paid order with nothing else to hand over (no other order to fulfil, no cash to collect): fulfils on tap, no
  pop-up, as today.
- Otherwise a pop-up, "Hand over {first name}'s orders?", lists each Paid and Cash owed order with its items, all
  ticked; a Cash owed one reads "collect $200 cash". Orders already fulfilled ("Already handed over: FL-PXTAXX (Sat 14
  Nov)") and still clearing ("Payment still clearing: FL-…") are listed but can't be ticked.
- Submitting fulfils the ticked Paid orders. A ticked Cash owed order is marked cash received, then fulfilled.
- Each order moves through `OrderPayments`' guarded transitions, so one that changed meanwhile is skipped and the
  message says so: "2 of 3 marked fulfilled. FL-XXXXXX had changed."
- Cash received and Cancel order stay on cash orders. The list's bulk Mark fulfilled is unchanged: it fulfils exactly
  what's selected.

## Not changing

The printed delivery run and its CSV (one row per order; repeat deliveries already sit together by night and
suburb), Claude's tools, the customer's order page and texts. No orders are merged or changed by the grouping.

## Testing

Feature tests:

- Grouping: phone, email in any case, name ignoring case and spaces, chaining, other drops and Pending/Expired/Failed
  orders left out, blank fields never linking.
- Fulfil: only ticked orders change; a ticked cash order ends paid and fulfilled; Processing and Fulfilled orders
  untouched; a single Paid order fulfils without a pop-up; the action shows on a fulfilled order while another of
  theirs waits; the message when an order had changed.
- List and page: To pick up and To deliver filter by method and status; To deliver sorts by night then suburb; "2
  orders" under the name; the order page lists the other orders' items.
