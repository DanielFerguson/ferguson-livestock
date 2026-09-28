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

        limit(id) {
            return Math.min(this.items[id].max, this.items[id].available);
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

        /** Take in a live snapshot. Same wording as the server uses when checkout finds too little left. */
        apply(status) {
            this.open = status.state === 'live' || status.state === 'sold_out';

            for (const [id, item] of Object.entries(this.items)) {
                const live = status.items?.[item.slug];
                if (!live) continue;
                item.available = live.available;

                if (item.box && this.$wire.box === id && live.available < 1) {
                    this.$wire.box = '';
                    this.notices.push(`The ${item.name} just sold out, so we’ve taken it off your order.`);
                } else if (!item.box && this.quantity(id) > live.available) {
                    this.$wire.extras[id] = live.available;
                    this.notices.push(live.available > 0
                        ? `There are only ${live.available} of the ${item.name} left, so we’ve changed your order to ${live.available}.`
                        : `The ${item.name} just sold out, so we’ve taken it off your order.`);
                }
            }
        },
    }));
});
