/**
 * Live drop status for every page.
 *
 * Polls /api/drop while something can change: every couple of seconds while a drop is live (or about to open),
 * every 30 seconds while one is scheduled, and not at all otherwise or while the tab is hidden. Each snapshot
 * is published as a `drop-status` event on window, and elements opt in with data attributes:
 *
 *   data-drop-show="live sold_out"   shown only in these states (none, scheduled, live, sold_out, closed, announced)
 *                                    "announced" stands in for none/closed while the next drop's date is announced
 *   data-drop-stock="product-slug"   "3 left", "Sold out" or "Available"
 *   data-drop-announced              shown only while the next drop's date is announced and hasn't passed
 *   data-drop-countdown              time until the drop opens, by the server's clock
 *   data-drop-held                   how many checkouts could still free stock up
 *   data-drop-paused                 shown while updates can't get through
 */
const root = document.body;
const url = root.dataset.dropStatus;
const every = Number(root.dataset.dropPoll) || 2000;

let snapshot = null;
let offset = 0; // the server's clock minus this device's, in milliseconds
let failures = 0;
let timer;
let lastState;
let lastView;

const serverNow = () => Date.now() + offset;
const opensIn = () => (snapshot?.drop ? Date.parse(snapshot.drop.opens_at) - serverNow() : Infinity);

/** The drop's state now. A scheduled drop turns live the moment it opens, without waiting for a poll. */
function state() {
    if (!snapshot?.drop) return 'none';
    if (snapshot.drop.state === 'scheduled' && opensIn() <= 0) return 'live';
    return snapshot.drop.state;
}

/** Whether a draft drop's date is announced and still ahead. It lapses on its own clock, with no poll. */
const announced = () => Boolean(snapshot?.announced) && Date.parse(snapshot.announced.opens_at) > serverNow();

/** What data-drop-show matches against: the state, except that an announced date replaces none and closed. */
function shown() {
    const now = state();
    return announced() && (now === 'none' || now === 'closed') ? 'announced' : now;
}

const viewKey = () => `${shown()}:${announced()}`;

/** Keep in step with App\Stock\StockLabel. */
function stockText(item) {
    if (!item) return '';
    if (item.available < 1) return 'Sold out';
    return item.available <= 10 ? `${item.available} left` : 'Available';
}

function countdown(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    const [d, h, m] = [Math.floor(s / 86400), Math.floor(s / 3600) % 24, Math.floor(s / 60) % 60];
    if (d) return `${d}d ${h}h`;
    if (h) return `${h}h ${m}m`;
    return m ? `${m}m ${s % 60}s` : `${s % 60}s`;
}

function nextPollIn() {
    if (document.hidden) return null;
    if (failures) return 15000;
    const now = state();
    if (now === 'live' || now === 'sold_out' || (now === 'scheduled' && opensIn() < 120000)) {
        return every * (0.85 + Math.random() * 0.3);
    }
    return now === 'scheduled' ? 30000 : null;
}

function schedule() {
    clearTimeout(timer);
    const wait = nextPollIn();
    if (wait !== null) timer = setTimeout(poll, wait);
}

async function poll() {
    clearTimeout(timer);
    const sent = Date.now();

    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        const received = Date.now();
        // Half the round trip, plus however long an edge cache held the response.
        const age = (Number(response.headers.get('Age')) || 0) * 1000;
        offset = Date.parse(data.server_time) + age + (received - sent) / 2 - received;
        failures = 0;
        snapshot = data;
        publish();
    } catch {
        failures++;
        render();
    }

    schedule();
}

function publish() {
    lastState = state();
    window.dropStatus = { ...snapshot, state: lastState, serverNow };
    render();
    window.dispatchEvent(new CustomEvent('drop-status', { detail: window.dropStatus }));
}

function render() {
    for (const el of document.querySelectorAll('[data-drop-paused]')) el.hidden = failures === 0;
    if (!snapshot) return; // keep the server's own rendering until the first snapshot arrives

    lastView = viewKey();
    const now = shown();
    for (const el of document.querySelectorAll('[data-drop-show]')) el.hidden = !el.dataset.dropShow.split(' ').includes(now);
    for (const el of document.querySelectorAll('[data-drop-announced]')) el.hidden = !announced();
    for (const el of document.querySelectorAll('[data-drop-stock]')) el.textContent = stockText(snapshot.items?.[el.dataset.dropStock]);
    for (const el of document.querySelectorAll('[data-drop-held]')) el.textContent = snapshot.held;
    tick();
}

function tick() {
    for (const el of document.querySelectorAll('[data-drop-countdown]')) el.textContent = countdown(opensIn());
}

setInterval(() => {
    if (!snapshot) return;
    if (state() !== lastState) {
        publish(); // the drop just opened on the server's clock
        poll();
    } else if (viewKey() !== lastView) {
        render(); // an announced date has just passed
    } else if (state() === 'scheduled') {
        tick();
    }
}, 1000);

document.addEventListener('visibilitychange', () => (document.hidden ? clearTimeout(timer) : poll()));
window.addEventListener('online', poll);

if (url) poll();
