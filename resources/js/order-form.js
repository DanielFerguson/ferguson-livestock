/**
 * The order form's browser side (see App\Livewire\OrderForm). Choices are set on $wire without a request;
 * this adds them up and trims them when the live stock feed shows less left than someone has chosen.
 */
const money = (cents) => new Intl.NumberFormat('en-AU', {
    style: 'currency',
    currency: 'AUD',
    minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
}).format(cents / 100);

document.addEventListener('alpine:init', () => {
    window.Alpine.data('orderForm', (items, open) => ({
        items,
        open,
        notices: [],
        money,

        init() {
            if (window.dropStatus) this.apply(window.dropStatus);
        },

        quantity(id) {
            return Number(this.$wire.extras[id] ?? 0);
        },

        /** How many of an item are chosen: a box is one or none. */
        chosen(id) {
            return this.items[id].box ? Number(this.$wire.box === String(id)) : this.quantity(id);
        },

        /** What's left of the stock an item sells from: its own, or the product it is a bundle of. */
        poolLeft(item) {
            return Object.values(this.items).find((other) => other.slug === item.pool)?.available ?? 0;
        },

        /** How much of that stock the rest of the order already uses. */
        usedByOthers(id) {
            const pool = this.items[id].pool;
            return Object.entries(this.items)
                .filter(([otherId, other]) => String(otherId) !== String(id) && other.pool === pool)
                .reduce((sum, [otherId, other]) => sum + this.chosen(otherId) * other.units, 0);
        },

        /** The most of an item that can still be added: limited by the order, and by stock the order shares with it. */
        limit(id) {
            const item = this.items[id];
            const room = Math.floor((this.poolLeft(item) - this.usedByOthers(id)) / item.units);
            return Math.max(0, Math.min(item.max, room));
        },

        add(id) {
            this.$wire.extras[id] = Math.min(this.quantity(id) + 1, this.limit(id));
        },

        remove(id) {
            this.$wire.extras[id] = Math.max(this.quantity(id) - 1, 0);
        },

        hasItems() {
            const box = this.$wire.box;
            return (box !== '' && box !== 'none') || Object.keys(this.items).some((id) => this.quantity(id) > 0);
        },

        ready() {
            const method = this.$wire.deliveryMethod;
            return this.hasItems() && (method === 'pickup' || (method === 'delivery' && this.$wire.deliveryDay !== ''));
        },

        lines() {
            return Object.entries(this.items)
                .map(([id, item]) => ({ name: item.name, count: item.box ? Number(this.$wire.box === id) : this.quantity(id), price: item.price }))
                .filter((line) => line.count > 0)
                .map((line) => ({ ...line, label: line.count > 1 ? `${line.count} × ${line.name}` : line.name, total: line.count * line.price }));
        },

        total(deliveryFee) {
            const items = this.lines().reduce((sum, line) => sum + line.total, 0);
            return items + (this.$wire.deliveryMethod === 'delivery' ? deliveryFee : 0);
        },

        /** The Turnstile token Cloudflare put in the form, or '' when the check is off or hasn't finished. */
        turnstileToken() {
            return this.$root.querySelector('[name="cf-turnstile-response"]')?.value ?? '';
        },

        resetTurnstile() {
            if (window.turnstile && document.getElementById('order-turnstile')) window.turnstile.reset('#order-turnstile');
        },

        /** Take in a live snapshot. Same wording as the server uses when checkout finds too little left. */
        apply(status) {
            this.open = status.state === 'live' || status.state === 'sold_out';

            for (const item of Object.values(this.items)) {
                const live = status.items?.[item.slug];
                if (!live) continue;
                item.available = live.available;
                item.pool = live.pool ?? item.pool;
                item.units = live.units ?? item.units;
            }

            // Items that share stock (a mince pack and 500g mince) are trimmed together, not one at a time: the biggest
            // bundles first, so a pack is kept and the singles beside it are cut. The server does the same (StockLedger::fit).
            const left = {};
            const byBundleSize = Object.entries(this.items).sort(([idA, a], [idB, b]) => b.units - a.units || Number(idA) - Number(idB));

            for (const [id, item] of byBundleSize) {
                const wanted = this.chosen(id);
                if (wanted < 1) continue;

                const have = (left[item.pool] ??= this.poolLeft(item));
                const fits = Math.min(wanted, Math.floor(have / item.units));
                left[item.pool] = have - fits * item.units;
                if (fits >= wanted) continue;

                if (item.box) {
                    this.$wire.box = '';
                } else {
                    this.$wire.extras[id] = fits;
                }

                this.notices.push(fits > 0
                    ? `There are only ${fits} of the ${item.name} left, so we’ve changed your order to ${fits}.`
                    : `The ${item.name} just sold out, so we’ve taken it off your order.`);
            }
        },
    }));
});
