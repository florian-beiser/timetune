/**
 * k6 load test: 500 players join, keep a WebSocket to Reverb open and place
 * their card in every round. The moderator drives the game in parallel
 * (scenario "host"), so the measured latency is moderator click -> phone.
 *
 * Run (game in the lobby, code and admin key from the moderator link):
 *   k6 run -e BASE_URL=https://timetune.example.de \
 *          -e WS_URL=wss://timetune.example.de/app/<REVERB_APP_KEY> \
 *          -e GAME_CODE=K7M2QX -e GAME_ID=1 -e ADMIN_KEY=... \
 *          loadtest/k6-players.js
 *
 * Thresholds: state changes reach 95 % of the clients within 1 s (server and
 * k6 clocks must be in sync, e.g. run k6 on the server or use NTP).
 */
import http from 'k6/http';
import ws from 'k6/ws';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8010';
const WS_URL = __ENV.WS_URL;
const GAME_CODE = __ENV.GAME_CODE || 'K7M2QX';
const GAME_ID = __ENV.GAME_ID || '1';
const ADMIN_KEY = __ENV.ADMIN_KEY || 'demo-admin-key-bitte-aendern';
const PLAYERS = Number(__ENV.PLAYERS || 500);
const ROUNDS = Number(__ENV.ROUNDS || 4);

const stateLatency = new Trend('state_change_latency', true);
const placements = new Counter('placements_ok');
const placementErrors = new Counter('placements_failed');

export const options = {
    scenarios: {
        players: {
            executor: 'per-vu-iterations',
            vus: PLAYERS,
            iterations: 1,
            maxDuration: '15m',
            exec: 'player',
        },
        host: {
            executor: 'per-vu-iterations',
            vus: 1,
            iterations: 1,
            startTime: '30s',
            maxDuration: '15m',
            exec: 'host',
        },
    },
    thresholds: {
        state_change_latency: ['p(95)<1000'],
        placements_failed: ['count<5'],
        http_req_failed: ['rate<0.01'],
    },
};

function xsrf(jar, url) {
    const cookies = jar.cookiesForURL(url);

    return cookies['XSRF-TOKEN']
        ? decodeURIComponent(cookies['XSRF-TOKEN'][0])
        : '';
}

function headers(jar) {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrf(jar, BASE_URL),
    };
}

export function player() {
    const jar = http.cookieJar();
    http.get(`${BASE_URL}/play/${GAME_CODE}`);

    const joined = http.post(
        `${BASE_URL}/api/games/${GAME_CODE}/join`,
        JSON.stringify({ name: `Last ${__VU}` }),
        { headers: headers(jar) },
    );
    check(joined, { joined: (r) => r.status === 201 });

    if (joined.status !== 201) {
        return;
    }

    let timelineSize = 1;

    ws.connect(
        `${WS_URL}?protocol=7&client=k6&version=8.4.0&flash=false`,
        {},
        (socket) => {
            socket.on('open', () => {
                socket.send(
                    JSON.stringify({
                        event: 'pusher:subscribe',
                        data: { channel: `game.${GAME_ID}` },
                    }),
                );
            });

            socket.on('message', (raw) => {
                const message = JSON.parse(raw);

                if (message.event !== 'StateChanged') {
                    return;
                }

                const state = JSON.parse(message.data);
                stateLatency.add(Date.now() - state.sent_at);

                if (state.state === 'ROUND_OPEN') {
                    // Players need a few seconds to decide; don't block the socket.
                    socket.setTimeout(() => {
                        const response = http.put(
                            `${BASE_URL}/api/rounds/${state.round_id}/placement`,
                            JSON.stringify({
                                position: Math.floor(
                                    Math.random() * (timelineSize + 1),
                                ),
                            }),
                            { headers: headers(jar) },
                        );
                        (response.status === 200
                            ? placements
                            : placementErrors
                        ).add(1);
                    }, Math.random() * 8000);
                }

                if (state.state === 'REVEALED') {
                    socket.setTimeout(() => {
                        const me = http.get(`${BASE_URL}/api/players/me`, {
                            headers: headers(jar),
                        });
                        timelineSize = me.json('timeline').length;
                    }, Math.random() * 3000);
                }

                if (state.state === 'FINISHED') {
                    socket.close();
                }
            });

            socket.setTimeout(() => socket.close(), 14 * 60 * 1000);
        },
    );
}

/**
 * Drives the game and measures how long the transition request takes; the
 * players measure the arrival of the broadcast relative to the click.
 */
export function host() {
    // Like the moderator's browser: the page unlocks the game for the session
    // and provides the XSRF cookie.
    const jar = http.cookieJar();
    http.get(`${BASE_URL}/host/${GAME_ID}?key=${ADMIN_KEY}`);

    const step = (from, to) => {
        const response = http.post(
            `${BASE_URL}/api/host/games/${GAME_ID}/advance`,
            JSON.stringify({ from, to }),
            { headers: headers(jar) },
        );
        check(response, {
            [`${from} -> ${to ?? 'next'}`]: (r) => r.status === 200,
        });
    };

    step('LOBBY');
    for (let round = 1; round <= ROUNDS; round++) {
        sleep(15); // players place their cards
        step('ROUND_OPEN');
        sleep(3);
        step('ROUND_CLOSED');
        sleep(8); // results are delivered
        if (round < ROUNDS) {
            step('REVEALED');
        }
    }
    step('REVEALED', 'FINISHED');
}
