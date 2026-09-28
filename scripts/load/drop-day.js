/**
 * Drop-day load test for staging: 500 visitors watching the live stock feed, each polling /api/drop every
 * couple of seconds like the page does, and some loading the order page.
 *
 *   k6 run -e BASE_URL=https://<staging-host> scripts/load/drop-day.js
 *
 * It doesn't start checkouts: those would create Stripe test sessions, and checkout allows 30 tries per IP
 * address every 10 minutes, so a single load generator can't stand in for many customers there.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL;

export const options = {
    scenarios: {
        watching: {
            executor: 'ramping-vus',
            stages: [
                { duration: '1m', target: 500 },
                { duration: '5m', target: 500 },
                { duration: '30s', target: 0 },
            ],
        },
    },
    thresholds: {
        'http_req_failed': ['rate<0.01'],
        'http_req_duration{name:drop}': ['p(95)<300'],
        'http_req_duration{name:order}': ['p(95)<800'],
    },
};

export default function () {
    if (Math.random() < 0.05) {
        const page = http.get(`${BASE_URL}/order`, { tags: { name: 'order' } });
        check(page, { 'order page loads': (response) => response.status === 200 });
    }

    const feed = http.get(`${BASE_URL}/api/drop`, { headers: { Accept: 'application/json' }, tags: { name: 'drop' } });
    check(feed, { 'feed answers': (response) => response.status === 200 && response.json('server_time') !== undefined });

    sleep(1.7 + Math.random() * 0.6);
}
