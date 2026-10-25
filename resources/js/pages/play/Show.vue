<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleCheck,
    CircleX,
    Lock,
    Music,
    Plus,
    ShieldCheck,
    Timer,
    WifiOff,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    answerBonus,
    heartbeat,
    join,
    me as meRoute,
    place,
    tiebreak,
} from '@/actions/App/Http/Controllers/Api/PlayerController';
import LiveChip from '@/components/game/LiveChip.vue';
import TimelineCard from '@/components/game/TimelineCard.vue';
import { useLive } from '@/composables/useLive';
import { useTheme } from '@/composables/useTheme';
import { api, ApiError } from '@/lib/api';
import type {
    Branding,
    Card,
    GameInfo,
    LeaderboardRow,
    Me,
    PublicState,
    RoundResult,
} from '@/types/game';

const props = defineProps<{
    game: GameInfo;
    branding: Branding;
    me: Me | null;
    finishedTop10: LeaderboardRow[] | null;
}>();

const themeVars = useTheme(() => props.branding.theme);

const me = ref<Me | null>(props.me);
const name = ref('');
const joining = ref(false);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const removedMessage = ref<string | null>(null);
const tiebreakInput = ref(me.value?.tiebreak_year?.toString() ?? '');
const flipped = ref(false);

const state = computed(() => me.value?.state.state ?? null);
const result = computed(() => me.value?.result ?? null);
const timeline = computed<Card[]>(() => me.value?.timeline ?? []);
const canPlay = computed(
    () => me.value !== null && me.value.joined_round !== null,
);

// --- Realtime ---------------------------------------------------------------

let resultFallback: number | undefined;

async function refresh() {
    try {
        me.value = await api<Me>(meRoute());
    } catch (e) {
        // 401: the moderator removed this player. Otherwise keep the last
        // known state while offline.
        if (e instanceof ApiError && e.status === 401 && me.value) {
            handleRemoved();
        }
    }
}

function handleRemoved(
    message = 'Du wurdest vom Moderator aus dem Spiel entfernt.',
) {
    me.value = null;
    removedMessage.value = message;
}

/** Spreads mass requests of 500 phones over a few seconds. */
function refreshSoon(maxDelay = 2000) {
    window.setTimeout(refresh, Math.random() * maxDelay);
}

function applyState(next: PublicState) {
    if (!me.value) {
        return;
    }

    const newRound =
        next.round_id !== me.value.state.round_id && next.round_id !== null;
    me.value.state = next;

    if (newRound) {
        me.value.placement = null;
        me.value.bonus_choice = null;
        me.value.result = null;
        me.value.joined_round = next.round_number;
        flipped.value = false;
    }

    window.clearTimeout(resultFallback);

    if (next.state === 'REVEALED' && !next.round_skipped) {
        // The PlayerResult event normally arrives first; otherwise ask.
        resultFallback = window.setTimeout(
            () => !me.value?.result && refreshSoon(),
            4000,
        );
    }

    if (next.state === 'FINISHED' || next.state === 'LOBBY') {
        refreshSoon();
    }
}

function applyResult(payload: RoundResult) {
    if (!me.value) {
        return;
    }

    me.value.result = payload;
    me.value.timeline = payload.timeline;
    me.value.score = payload.score;
    me.value.rank = payload.rank;
    me.value.total_players = payload.total_players;
    me.value.bonus_points = payload.bonus_points;
}

const { isLive } = useLive({
    gameId: computed(() => (me.value ? props.game.id : null)),
    playerId: computed(() => me.value?.player.id ?? null),
    handlers: {
        StateChanged: applyState,
        SubmissionsCount: (payload: { total_players: number }) => {
            if (me.value) {
                me.value.total_players = payload.total_players;
            }
        },
    },
    playerHandlers: {
        PlayerResult: applyResult,
        PlayerRemoved: (payload: { message: string }) =>
            handleRemoved(payload.message),
    },
    poll: refresh,
});

watch(result, (value) => {
    flipped.value = false;

    if (value) {
        window.setTimeout(() => (flipped.value = true), 50);
    }
});

// --- Actions ----------------------------------------------------------------

async function submitJoin() {
    joining.value = true;
    error.value = null;

    try {
        const response = await api<{ me: Me }>(join(props.game.code), {
            name: name.value,
        });
        me.value = response.me;
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        joining.value = false;
    }
}

async function choose(position: number) {
    const current = me.value;

    if (!current?.state.round_id || state.value !== 'ROUND_OPEN') {
        return;
    }

    const previous = current.placement;
    current.placement = position;
    notice.value = null;

    try {
        await api(place(current.state.round_id), { position });
    } catch (e) {
        current.placement = previous;
        notice.value = e instanceof Error ? e.message : String(e);

        if (e instanceof ApiError && e.status === 409) {
            void refresh();
        }
    }
}

async function chooseBonus(choice: number) {
    const current = me.value;

    if (!current?.state.round_id || state.value !== 'ROUND_OPEN') {
        return;
    }

    current.bonus_choice = choice;

    try {
        await api(answerBonus(current.state.round_id), { choice });
    } catch (e) {
        notice.value = e instanceof Error ? e.message : String(e);
    }
}

async function submitTiebreak() {
    if (!me.value) {
        return;
    }

    error.value = null;

    try {
        await api(tiebreak(props.game.id), {
            guessed_year: Number(tiebreakInput.value),
        });
        me.value.tiebreak_year = Number(tiebreakInput.value);
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    }
}

// --- Countdown --------------------------------------------------------------

const now = ref(Date.now());
let clock: number | undefined;
let pulse: number | undefined;

/** Lets the moderator see who is still active (phone unlocked, page open). */
function sendHeartbeat() {
    if (me.value && document.visibilityState === 'visible') {
        api(heartbeat()).catch((e) => {
            if (e instanceof ApiError && e.status === 401) {
                handleRemoved();
            }
        });
    }
}

onMounted(() => {
    clock = window.setInterval(() => (now.value = Date.now()), 500);
    pulse = window.setInterval(sendHeartbeat, 45000);
    document.addEventListener('visibilitychange', sendHeartbeat);
});
onBeforeUnmount(() => {
    window.clearInterval(clock);
    window.clearInterval(pulse);
    window.clearTimeout(resultFallback);
    document.removeEventListener('visibilitychange', sendHeartbeat);
});

const countdown = computed(() => {
    const closesAt = me.value?.state.closes_at;

    if (!closesAt || state.value !== 'ROUND_OPEN') {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.ceil((new Date(closesAt).getTime() - now.value) / 1000),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

// --- Timeline rows ----------------------------------------------------------

type Row =
    | { kind: 'card'; key: string; card: Card; isNew?: boolean }
    | { kind: 'gap'; key: string; position: number; selected: boolean }
    | { kind: 'ghost'; key: string }
    | { kind: 'wrong'; key: string };

const gapRows = computed<Row[]>(() => {
    const rows: Row[] = [];
    const selected = me.value?.placement ?? null;

    timeline.value.forEach((card, index) => {
        rows.push({
            kind: 'gap',
            key: `gap-${index}`,
            position: index,
            selected: selected === index,
        });
        rows.push({ kind: 'card', key: `card-${card.id}`, card });
    });

    rows.push({
        kind: 'gap',
        key: `gap-${timeline.value.length}`,
        position: timeline.value.length,
        selected: selected === timeline.value.length,
    });

    return rows;
});

const resultRows = computed<Row[]>(() => {
    const value = result.value;

    if (!value) {
        return [];
    }

    if (value.is_correct) {
        return value.timeline.map((card) => ({
            kind: 'card' as const,
            key: `card-${card.id}`,
            card,
            isNew: card.id === value.song.id,
        }));
    }

    const rows: Row[] = [];
    value.timeline.forEach((card, index) => {
        if (index === value.correct_position) {
            rows.push({ kind: 'ghost', key: 'ghost' });
        }
        if (index === value.position) {
            rows.push({ kind: 'wrong', key: 'wrong' });
        }
        rows.push({ kind: 'card', key: `card-${card.id}`, card });
    });

    if (value.correct_position === value.timeline.length) {
        rows.push({ kind: 'ghost', key: 'ghost' });
    }
    if (value.position === value.timeline.length) {
        rows.push({ kind: 'wrong', key: 'wrong' });
    }

    return rows;
});

const roundLabel = computed(
    () =>
        `Stück ${me.value?.state.round_number ?? 0} von ${me.value?.state.total_rounds ?? 0}`,
);

const finalRankClass = computed(() => {
    switch (me.value?.rank) {
        case 1:
            return 'bg-grad-gold text-navy';
        case 2:
            return 'bg-grad-silver';
        case 3:
            return 'bg-grad-bronze';
        default:
            return 'bg-royal';
    }
});

function onTiebreakInput(event: Event) {
    const input = event.target as HTMLInputElement;
    tiebreakInput.value = input.value.replace(/\D/g, '').slice(0, 4);
    input.value = tiebreakInput.value;
}
</script>

<template>
    <Head :title="game.name" />

    <div class="dark min-h-dvh bg-night text-white" :style="themeVars">
        <div
            class="mx-auto flex min-h-dvh max-w-md flex-col pb-[env(safe-area-inset-bottom)]"
        >
            <!-- Offline hint -->
            <div
                v-if="me && !isLive"
                class="flex items-center justify-center gap-2 bg-white/10 px-4 py-1.5 text-xs text-white/75"
            >
                <WifiOff class="size-3.5" />
                Verbindung wird wiederhergestellt …
            </div>

            <!-- 1a Join -->
            <template v-if="!me">
                <div class="flex flex-col gap-2 px-6 pt-14">
                    <img
                        v-if="branding.logo_url"
                        :src="branding.logo_url"
                        :alt="game.name"
                        class="mb-4 max-h-16 max-w-56 self-start object-contain"
                    />
                    <div
                        v-if="removedMessage"
                        class="mb-4 rounded-card bg-alert/15 px-4 py-3 text-sm text-[#ff8a95]"
                    >
                        {{ removedMessage }} Du kannst neu beitreten.
                    </div>
                    <div class="flex items-center gap-2 text-xs text-white/50">
                        <Music class="size-3.5" />
                        <span>{{ game.name }} · Spielcode {{ game.code }}</span>
                    </div>
                    <h1 class="text-[32px] leading-tight font-semibold">
                        Konzert-Hitster
                    </h1>
                    <p
                        class="text-base leading-normal text-pretty text-white/75"
                    >
                        Wir spielen live, du ordnest jedes Stück in deine
                        Zeitleiste ein.
                    </p>
                </div>

                <div
                    v-if="finishedTop10"
                    class="flex flex-1 flex-col gap-3 px-6 pt-10"
                >
                    <div class="rounded-card bg-white/15 px-4 py-3 text-sm">
                        <b class="font-semibold">Spiel bereits beendet.</b>
                        Das ist die Rangliste:
                    </div>
                    <div
                        v-for="row in finishedTop10"
                        :key="row.player_id"
                        class="flex items-center gap-3 rounded-card bg-white/6 px-4 py-2.5"
                    >
                        <span class="w-6 text-white/50">{{ row.rank }}</span>
                        <span>{{ row.name }}</span>
                        <span class="ml-auto font-semibold">{{
                            row.score
                        }}</span>
                    </div>
                </div>

                <form
                    v-else
                    class="flex flex-1 flex-col gap-2 px-6 pt-10"
                    @submit.prevent="submitJoin"
                >
                    <label for="name" class="text-xs text-white/75">
                        Dein Name
                    </label>
                    <div
                        class="flex h-13 items-center justify-between rounded-btn border border-royal bg-white/6 px-4 focus-within:ring-3 focus-within:ring-royal/35"
                    >
                        <input
                            id="name"
                            v-model="name"
                            maxlength="20"
                            autocomplete="nickname"
                            enterkeyhint="go"
                            required
                            class="w-full bg-transparent text-base outline-none placeholder:text-white/40"
                            placeholder="z. B. Anna"
                        />
                        <span class="text-xs text-white/50">
                            {{ name.length }}/20
                        </span>
                    </div>
                    <p v-if="error" class="text-sm text-[#ff8a95]">
                        {{ error }}
                    </p>
                    <p v-else class="text-xs text-white/50">
                        Gleiche Namen werden nummeriert, z. B. „Anna 2“.
                    </p>
                    <button
                        type="submit"
                        :disabled="joining || name.trim() === ''"
                        class="mt-4 h-13 rounded-btn bg-royal text-base font-semibold transition-colors hover:bg-royal-hover disabled:opacity-50"
                    >
                        {{ joining ? 'Einen Moment …' : 'Mitspielen' }}
                    </button>
                </form>

                <div
                    class="flex gap-2.5 px-6 pt-6 pb-8 text-xs leading-normal text-white/50"
                >
                    <ShieldCheck class="mt-0.5 size-4 shrink-0" />
                    <span class="text-pretty">
                        Wir speichern nur deinen Anzeigenamen, kein Konto, kein
                        Tracking. Alle Daten werden 30 Tage nach Spielende
                        gelöscht.
                    </span>
                </div>
            </template>

            <!-- 1b Lobby -->
            <template v-else-if="state === 'LOBBY'">
                <header
                    class="flex items-center justify-between border-b border-white/15 px-5 pt-5 pb-4"
                >
                    <img
                        v-if="branding.logo_url"
                        :src="branding.logo_url"
                        :alt="game.name"
                        class="h-6 max-w-40 object-contain"
                    />
                    <span v-else class="text-sm font-semibold"
                        >Konzert-Hitster</span
                    >
                    <span class="text-xs text-white/50">{{
                        me.player.name
                    }}</span>
                </header>
                <div
                    class="flex flex-1 flex-col items-center justify-center gap-6 p-6"
                >
                    <div class="text-xs tracking-wider text-white/50 uppercase">
                        Deine Startkarte
                    </div>
                    <div
                        class="flex w-60 flex-col items-center justify-center gap-3 overflow-hidden rounded-podium bg-white text-center text-navy shadow-ranking"
                        :class="me.start_card.image ? 'pb-6' : 'h-75 px-4'"
                    >
                        <img
                            v-if="me.start_card.image"
                            :src="me.start_card.image.sm"
                            alt=""
                            class="mb-1 aspect-[2/1] w-full object-cover"
                        />
                        <div class="text-7xl leading-none font-semibold">
                            {{ me.start_card.year }}
                        </div>
                        <div class="h-0.5 w-10 bg-royal" />
                        <div class="text-xl font-semibold">
                            {{ me.start_card.title }}
                        </div>
                        <div class="text-sm text-navy/70">
                            {{ me.start_card.artist }}
                        </div>
                    </div>
                    <div class="text-sm text-white/75">Zählt als 1 Karte</div>
                </div>
                <div
                    class="flex flex-col items-center gap-1.5 px-6 pt-6 pb-10 text-center"
                >
                    <div class="text-xl font-semibold">Gleich geht’s los</div>
                    <div class="text-sm text-white/50">
                        Warte auf das erste Stück …
                    </div>
                </div>
            </template>

            <!-- 1c Round open / 1d closed -->
            <template
                v-else-if="
                    (state === 'ROUND_OPEN' || state === 'ROUND_CLOSED') &&
                    canPlay
                "
            >
                <header
                    class="flex items-center justify-between border-b border-white/15 px-5 pt-5 pb-3"
                >
                    <div class="flex items-center gap-2">
                        <LiveChip v-if="state === 'ROUND_OPEN'" />
                        <span class="text-sm font-semibold">{{
                            roundLabel
                        }}</span>
                    </div>
                    <span class="text-xs text-white/75">
                        Deine Karten:
                        <b class="font-semibold text-white">{{ me.score }}</b>
                    </span>
                </header>

                <div
                    v-if="state === 'ROUND_CLOSED'"
                    class="mx-5 mt-4 flex items-center gap-2.5 rounded-card bg-white/15 px-3.5 py-3 text-sm"
                >
                    <Lock class="size-4" />
                    <span>
                        <b class="font-semibold">Abgabe geschlossen.</b>
                        Gleich wird aufgelöst.
                    </span>
                </div>

                <div class="flex flex-1 flex-col gap-1.5 px-5 pt-4 pb-6">
                    <div
                        v-if="state === 'ROUND_OPEN'"
                        class="flex flex-col items-center gap-2 pt-1 pb-3"
                    >
                        <div
                            class="flex h-30 w-24 items-center justify-center rounded-xl bg-royal text-[56px] font-semibold"
                        >
                            ?
                        </div>
                        <div
                            v-if="countdown"
                            class="flex items-center gap-1 text-xs text-amber"
                        >
                            <Timer class="size-3.5" />
                            Schließt automatisch in {{ countdown }}
                        </div>
                    </div>

                    <div
                        v-if="me.state.bonus_options"
                        class="mb-2 flex flex-col gap-2 rounded-card border border-white/15 p-3"
                    >
                        <div class="flex justify-between text-xs">
                            <span class="font-semibold">
                                Bonusfrage · Wer ist der Interpret?
                            </span>
                            <span class="text-white/50">+1 Punkt</span>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5">
                            <button
                                v-for="(option, index) in me.state
                                    .bonus_options"
                                :key="option"
                                type="button"
                                :disabled="state !== 'ROUND_OPEN'"
                                class="min-h-11 rounded-btn border px-2 text-sm"
                                :class="
                                    me.bonus_choice === index
                                        ? 'border-royal bg-royal'
                                        : 'border-white/15'
                                "
                                @click="chooseBonus(index)"
                            >
                                {{ option }}
                            </button>
                        </div>
                    </div>

                    <template v-for="row in gapRows" :key="row.key">
                        <TimelineCard
                            v-if="row.kind === 'card'"
                            :card="row.card"
                        />
                        <template v-else-if="row.kind === 'gap'">
                            <div
                                v-if="row.selected"
                                class="flex h-14 items-center gap-3.5 rounded-btn px-3.5"
                                :class="
                                    state === 'ROUND_OPEN'
                                        ? 'bg-royal shadow-[0_0_0_3px_rgba(25,103,230,0.35)]'
                                        : 'border border-royal bg-royal/15'
                                "
                            >
                                <span
                                    class="flex h-10 w-8 items-center justify-center rounded-md text-[22px] font-semibold"
                                    :class="
                                        state === 'ROUND_OPEN'
                                            ? 'bg-white text-royal'
                                            : 'bg-royal'
                                    "
                                >
                                    ?
                                </span>
                                <span class="text-sm font-semibold">
                                    Deine Wahl
                                </span>
                                <CircleCheck
                                    v-if="state === 'ROUND_OPEN'"
                                    class="ml-auto size-5"
                                />
                                <Lock
                                    v-else
                                    class="ml-auto size-4 text-white/50"
                                />
                            </div>
                            <button
                                v-else-if="state === 'ROUND_OPEN'"
                                type="button"
                                class="flex h-11 items-center justify-center gap-2 rounded-btn border border-dashed border-white/50 text-sm text-white/75 transition-colors hover:border-royal hover:bg-royal/15 active:bg-royal/25"
                                @click="choose(row.position)"
                            >
                                <Plus class="size-3" />
                                hier einfügen
                            </button>
                            <div v-else class="flex h-3 items-center">
                                <div
                                    class="flex-1 border-t border-dashed border-white/15"
                                />
                            </div>
                        </template>
                    </template>

                    <div
                        v-if="notice"
                        class="mt-2 rounded-card bg-alert/15 px-3 py-2 text-center text-sm text-[#ff8a95]"
                    >
                        {{ notice }}
                    </div>
                    <div
                        v-else-if="state === 'ROUND_OPEN'"
                        class="pt-2.5 text-center text-xs text-white/50"
                    >
                        {{
                            me.placement === null
                                ? 'Tippe auf eine Lücke. Keine Abgabe zählt als falsch.'
                                : 'Du kannst bis zum Schließen ändern.'
                        }}
                    </div>
                </div>
            </template>

            <!-- 1e / 1f Reveal -->
            <template v-else-if="state === 'REVEALED' && result">
                <header
                    class="flex items-center justify-between border-b border-white/15 px-5 pt-5 pb-3"
                >
                    <span class="text-sm font-semibold">{{ roundLabel }}</span>
                    <span class="text-xs text-white/75">Auflösung</span>
                </header>

                <div
                    class="flex items-center gap-4 p-5"
                    :class="
                        !result.participated
                            ? 'bg-white/15'
                            : result.is_correct
                              ? 'bg-success'
                              : 'bg-alert'
                    "
                >
                    <div
                        class="flip-card h-22.5 w-18 shrink-0"
                        :class="{ flipped }"
                    >
                        <div class="flip-inner">
                            <div
                                class="flip-face flex items-center justify-center rounded-xl bg-royal text-4xl font-semibold"
                            >
                                ?
                            </div>
                            <div
                                class="flip-face flip-back flex items-center justify-center overflow-hidden rounded-xl bg-white text-[22px] font-semibold text-navy"
                            >
                                <template v-if="result.song.image">
                                    <img
                                        :src="result.song.image.sm"
                                        alt=""
                                        class="absolute inset-0 size-full object-cover"
                                    />
                                    <span
                                        class="absolute inset-x-0 bottom-0 bg-white/90 py-0.5 text-center text-base"
                                    >
                                        {{ result.song.year }}
                                    </span>
                                </template>
                                <template v-else>
                                    {{ result.song.year }}
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <div
                            class="flex items-center gap-2 text-[26px] font-semibold"
                        >
                            <template v-if="!result.participated">
                                Aufgelöst
                            </template>
                            <template v-else-if="result.is_correct">
                                <CircleCheck class="size-6" />Richtig!
                            </template>
                            <template v-else>
                                <CircleX class="size-6" />Daneben
                            </template>
                        </div>
                        <div class="text-sm">
                            {{ result.song.title }} · {{ result.song.artist }}
                        </div>
                        <div
                            v-if="result.participated && !result.is_correct"
                            class="text-xs text-white/75"
                        >
                            {{
                                result.submitted
                                    ? 'Die Karte verfällt.'
                                    : 'Keine Abgabe – die Karte verfällt.'
                            }}
                        </div>
                        <div
                            v-if="result.bonus_correct !== null"
                            class="text-xs text-white/75"
                        >
                            Bonusfrage:
                            {{
                                result.bonus_correct
                                    ? 'richtig, +1 Punkt'
                                    : 'leider falsch'
                            }}
                        </div>
                    </div>
                </div>

                <div class="flex flex-1 flex-col gap-1.5 p-5">
                    <template v-for="row in resultRows" :key="row.key">
                        <TimelineCard
                            v-if="row.kind === 'card'"
                            :card="row.card"
                            :variant="row.isNew ? 'new' : 'default'"
                        />
                        <div
                            v-else-if="
                                row.kind === 'ghost' && result.participated
                            "
                            class="flex h-11 items-center gap-2.5 rounded-btn border border-dashed border-success-light px-3.5 text-sm text-success-light"
                        >
                            <ArrowRight class="size-4" />
                            <span>
                                <b class="font-semibold">{{
                                    result.song.year
                                }}</b>
                                hätte hierher gehört
                            </span>
                        </div>
                        <div
                            v-else-if="row.kind === 'wrong'"
                            class="flex h-11 items-center gap-2.5 rounded-btn border border-alert bg-alert/15 px-3.5 text-sm"
                        >
                            <X class="size-4" />
                            <span>Deine Wahl</span>
                        </div>
                    </template>
                </div>

                <div
                    class="grid grid-cols-2 gap-3 border-t border-white/15 px-5 pt-4 pb-8"
                >
                    <div>
                        <div class="text-xs text-white/50">Deine Karten</div>
                        <div class="text-[26px] font-semibold">
                            {{ me.score }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-white/50">Aktueller Rang</div>
                        <div class="text-[26px] font-semibold">
                            {{ me.rank }}
                            <span class="text-sm font-normal text-white/50">
                                von {{ me.total_players }}
                            </span>
                        </div>
                    </div>
                </div>
            </template>

            <!-- 1g Tiebreaker -->
            <template v-else-if="state === 'TIEBREAKER'">
                <header
                    class="flex items-center justify-between border-b border-white/15 px-5 pt-5 pb-3"
                >
                    <span class="text-sm font-semibold">Tiebreaker</span>
                    <span class="text-xs text-white/75">Bei Gleichstand</span>
                </header>
                <form
                    class="flex flex-1 flex-col gap-6 px-6 py-10"
                    @submit.prevent="submitTiebreak"
                >
                    <div class="flex flex-col gap-2">
                        <div
                            class="text-[26px] leading-tight font-semibold text-pretty"
                        >
                            Aus welchem Jahr ist dieser Song?
                        </div>
                        <div class="text-base text-white/75">
                            {{ me.state.tiebreaker?.title }} ·
                            {{ me.state.tiebreaker?.artist }}
                        </div>
                    </div>

                    <label class="relative grid grid-cols-4 gap-2">
                        <span class="sr-only">Jahr</span>
                        <span
                            v-for="index in 4"
                            :key="index"
                            class="flex h-18 items-center justify-center rounded-btn border text-4xl font-semibold"
                            :class="[
                                tiebreakInput.length === index - 1 &&
                                me.tiebreak_year === null
                                    ? 'border-royal bg-white/8 shadow-[0_0_0_3px_rgba(25,103,230,0.35)]'
                                    : tiebreakInput[index - 1]
                                      ? 'border-white/15 bg-white/8'
                                      : 'border-dashed border-white/15 bg-white/4 text-white/15',
                            ]"
                        >
                            {{ tiebreakInput[index - 1] ?? '0' }}
                        </span>
                        <input
                            :value="tiebreakInput"
                            inputmode="numeric"
                            autocomplete="off"
                            maxlength="4"
                            :disabled="me.tiebreak_year !== null"
                            class="absolute inset-0 opacity-0"
                            @input="onTiebreakInput"
                        />
                    </label>

                    <p v-if="error" class="text-sm text-[#ff8a95]">
                        {{ error }}
                    </p>
                    <div
                        class="text-xs leading-normal text-pretty text-white/50"
                    >
                        Die kleinste Abweichung gewinnt. Bei gleicher Abweichung
                        zählt die frühere Abgabe.
                    </div>

                    <button
                        v-if="me.tiebreak_year === null"
                        type="submit"
                        :disabled="tiebreakInput.length !== 4"
                        class="mt-auto h-13 rounded-btn bg-royal text-base font-semibold transition-colors hover:bg-royal-hover disabled:opacity-50"
                    >
                        Abschicken
                    </button>
                    <div
                        v-else
                        class="mt-auto flex items-center justify-center gap-2 rounded-card bg-white/15 py-3 text-sm"
                    >
                        <CircleCheck class="size-4" />
                        Abgegeben: {{ me.tiebreak_year }}
                    </div>
                </form>
            </template>

            <!-- 1h Finished -->
            <template v-else-if="state === 'FINISHED'">
                <header
                    class="flex items-center justify-between border-b border-white/15 px-5 pt-5 pb-3"
                >
                    <img
                        v-if="branding.logo_url"
                        :src="branding.logo_url"
                        :alt="game.name"
                        class="h-6 max-w-40 object-contain"
                    />
                    <span v-else class="text-sm font-semibold"
                        >Konzert-Hitster</span
                    >
                    <span
                        class="rounded-full bg-white/15 px-2.5 py-0.5 text-xs"
                    >
                        Spiel beendet
                    </span>
                </header>
                <div
                    class="m-5 flex items-center gap-5 rounded-podium p-6 shadow-ranking"
                    :class="finalRankClass"
                >
                    <div class="flex flex-col">
                        <span class="text-xs">Dein Rang</span>
                        <span class="text-[64px] leading-none font-semibold">
                            {{ me.rank }}.
                        </span>
                    </div>
                    <div class="ml-auto flex flex-col gap-0.5 text-right">
                        <span class="text-[26px] font-semibold">
                            {{ me.score }} Karten
                        </span>
                        <span class="text-xs">
                            von {{ me.total_players }} Spielern
                        </span>
                        <span v-if="me.bonus_points" class="text-xs">
                            + {{ me.bonus_points }} Bonuspunkte
                        </span>
                        <span v-if="me.tiebreak_year" class="text-xs">
                            Tiebreaker: {{ me.tiebreak_year }}
                            <template v-if="me.tiebreak_deviation !== null">
                                ({{ me.tiebreak_deviation }}
                                {{
                                    me.tiebreak_deviation === 1
                                        ? 'Jahr'
                                        : 'Jahre'
                                }}
                                daneben)
                            </template>
                        </span>
                    </div>
                </div>
                <div class="px-5 pb-2 text-xs text-white/50">
                    Deine finale Zeitleiste
                </div>
                <div class="flex flex-1 flex-col gap-1 px-5 pb-6">
                    <TimelineCard
                        v-for="card in timeline"
                        :key="card.id"
                        :card="card"
                        variant="compact"
                    />
                </div>
            </template>

            <!-- Joined after closing, or revealed while waiting for the result -->
            <template v-else>
                <header
                    class="flex items-center justify-between border-b border-white/15 px-5 pt-5 pb-3"
                >
                    <span class="text-sm font-semibold">{{ roundLabel }}</span>
                    <span class="text-xs text-white/75">
                        Deine Karten:
                        <b class="font-semibold text-white">{{ me.score }}</b>
                    </span>
                </header>
                <div
                    class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
                >
                    <div class="text-xl font-semibold">
                        {{
                            me.state.round_skipped
                                ? 'Stück übersprungen'
                                : state === 'REVEALED'
                                  ? 'Wird ausgewertet …'
                                  : 'Du bist ab dem nächsten Stück dabei'
                        }}
                    </div>
                    <div class="text-sm text-white/50">
                        {{
                            me.state.round_skipped
                                ? 'Dieses Stück wird für niemanden gewertet.'
                                : 'Bleib dran, gleich geht es weiter.'
                        }}
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.flip-card {
    perspective: 600px;
}

.flip-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    transition: transform 0.6s var(--ease-standard);
}

.flipped .flip-inner {
    transform: rotateY(180deg);
}

.flip-face {
    position: absolute;
    inset: 0;
    backface-visibility: hidden;
}

.flip-back {
    transform: rotateY(180deg);
}

@media (prefers-reduced-motion: reduce) {
    .flip-inner {
        transition: none;
    }
}
</style>
