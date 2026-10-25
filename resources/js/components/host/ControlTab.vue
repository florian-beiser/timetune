<script setup lang="ts">
import {
    Check,
    Eye,
    EyeOff,
    FastForward,
    Lock,
    LockOpen,
    Monitor,
    Play,
    Trophy,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    advance,
    reopen,
    skip,
    togglePlayer,
} from '@/actions/App/Http/Controllers/Host/GameActionController';
import LiveChip from '@/components/game/LiveChip.vue';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import type { GameStateName, HostProps, SongRow } from '@/types/game';

const props = defineProps<{
    host: HostProps;
    submitted: number;
    totalPlayers: number;
    busy: boolean;
    run: (action: () => Promise<unknown>) => Promise<void>;
}>();

const emit = defineEmits<{ openTab: [tab: 'songs' | 'settings'] }>();

const state = computed(() => props.host.state.state);
const isLobby = computed(() => state.value === 'LOBBY');

function go(to?: GameStateName) {
    return props.run(() =>
        api(advance(props.host.game.id), {
            from: state.value,
            to: to ?? null,
        }),
    );
}

const primary = computed(() => {
    switch (state.value) {
        case 'LOBBY':
            return { label: 'Spiel starten', icon: Play };
        case 'ROUND_OPEN':
            return { label: 'Abgabe schließen', icon: Lock };
        case 'ROUND_CLOSED':
            return { label: 'Auflösen', icon: Eye };
        case 'REVEALED':
            return props.host.has_next_round
                ? { label: 'Nächstes Stück', icon: Play }
                : null;
        case 'TIEBREAKER':
            return { label: 'Ergebnis zeigen', icon: Trophy };
        default:
            return null;
    }
});

const statusTitle = computed(() => {
    const round = `Stück ${props.host.state.round_number} von ${props.host.state.total_rounds}`;

    switch (state.value) {
        case 'ROUND_OPEN':
            return `${round} · Abgabe offen`;
        case 'ROUND_CLOSED':
            return `${round} · Abgabe geschlossen`;
        case 'REVEALED':
            return `${round} · ${props.host.state.round_skipped ? 'übersprungen' : 'aufgelöst'}`;
        case 'TIEBREAKER':
            return 'Tiebreaker läuft';
        case 'FINISHED':
            return 'Spiel beendet';
        default:
            return 'Bereit zum Start?';
    }
});

// Lobby: what is needed before the start
const checklist = computed(() => {
    const songs = props.host.songs;

    return [
        {
            ok: songs.round.length > 0,
            required: true,
            text: `${songs.round.length} Runden-Songs`,
            missing: 'Keine Runden-Songs',
            tab: 'songs' as const,
        },
        {
            ok: songs.start_pool.length > 0,
            required: true,
            text: `${songs.start_pool.length} Startkarten`,
            missing: 'Kein Startkarten-Pool – niemand kann beitreten',
            tab: 'songs' as const,
        },
        {
            ok: songs.tiebreaker.length > 0,
            required: false,
            text: `Tiebreaker: ${songs.tiebreaker[0]?.title ?? ''}`,
            missing: 'Kein Tiebreaker-Song (optional)',
            tab: 'songs' as const,
        },
    ];
});
const canStart = computed(() =>
    checklist.value.every((item) => item.ok || !item.required),
);

const submittedPercent = computed(() =>
    props.totalPlayers
        ? Math.round((props.submitted / props.totalPlayers) * 100)
        : 0,
);

const now = ref(Date.now());
let clock: number | undefined;
onMounted(
    () => (clock = window.setInterval(() => (now.value = Date.now()), 1000)),
);
onBeforeUnmount(() => window.clearInterval(clock));

const openSince = computed(() => {
    if (!props.host.round_opened_at) {
        return '–';
    }

    const seconds = Math.max(
        0,
        Math.floor(
            (now.value - new Date(props.host.round_opened_at).getTime()) / 1000,
        ),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

function statusText(song: SongRow): string {
    switch (song.status) {
        case 'running':
            return 'Läuft';
        case 'skipped':
            return 'Übersprungen';
        case 'revealed':
            return `${song.percent_correct ?? 0} % richtig`;
        default:
            return '';
    }
}
</script>

<template>
    <div class="grid md:grid-cols-[minmax(0,1fr)_360px]">
        <div class="flex flex-col gap-5 border-navy/15 p-6 md:border-r">
            <div class="flex items-center gap-3">
                <LiveChip v-if="state === 'ROUND_OPEN'" />
                <span class="text-xl font-semibold">{{ statusTitle }}</span>
            </div>

            <!-- Lobby: readiness checklist -->
            <template v-if="isLobby">
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="item in checklist"
                        :key="item.text"
                        class="flex items-center gap-3 rounded-card border px-4 py-2.5 text-sm"
                        :class="
                            item.ok
                                ? 'border-navy/15'
                                : item.required
                                  ? 'border-alert/40 bg-alert/5'
                                  : 'border-amber/60 bg-amber-soft'
                        "
                    >
                        <Check v-if="item.ok" class="size-4 text-success" />
                        <X
                            v-else-if="item.required"
                            class="size-4 text-alert"
                        />
                        <TriangleAlert v-else class="size-4 text-amber-dark" />
                        <span class="truncate">
                            {{ item.ok ? item.text : item.missing }}
                        </span>
                        <button
                            v-if="!item.ok"
                            type="button"
                            class="ml-auto text-xs font-semibold text-royal hover:underline"
                            @click="emit('openTab', item.tab)"
                        >
                            Songs bearbeiten
                        </button>
                    </li>
                    <li
                        class="flex items-center gap-3 rounded-card border border-navy/15 px-4 py-2.5 text-sm"
                    >
                        <Check
                            v-if="totalPlayers > 0"
                            class="size-4 text-success"
                        />
                        <TriangleAlert v-else class="size-4 text-amber-dark" />
                        {{
                            totalPlayers > 0
                                ? `${totalPlayers} Spieler dabei`
                                : 'Noch niemand beigetreten'
                        }}
                    </li>
                    <li
                        class="flex flex-wrap items-center gap-3 rounded-card border border-navy/15 px-4 py-2.5 text-sm"
                    >
                        <Monitor class="size-4 text-navy/70" />
                        <span class="min-w-0 truncate">
                            QR-Code führt zu
                            <b class="font-semibold">{{
                                host.join_url_short
                            }}</b>
                        </span>
                        <span class="ml-auto flex gap-3">
                            <button
                                type="button"
                                class="text-xs font-semibold text-royal hover:underline"
                                @click="emit('openTab', 'settings')"
                            >
                                Ändern
                            </button>
                            <a
                                :href="host.screen_url"
                                target="_blank"
                                class="text-xs font-semibold text-royal hover:underline"
                            >
                                Leinwand öffnen
                            </a>
                        </span>
                    </li>
                </ul>
                <p class="text-xs text-navy/70">
                    Startet Stück 1. Beitreten ist auch während des Spiels
                    möglich.
                </p>
            </template>

            <!-- Running: current song with solution and stats -->
            <template v-else>
                <div
                    v-if="
                        host.current_song &&
                        state !== 'FINISHED' &&
                        state !== 'TIEBREAKER'
                    "
                    class="flex flex-wrap items-center gap-5 rounded-card border border-navy/15 bg-amber-soft p-5"
                >
                    <img
                        v-if="host.current_song.image"
                        :src="host.current_song.image.sm"
                        alt=""
                        class="size-16 rounded-card object-cover"
                    />
                    <div class="text-5xl leading-none font-semibold">
                        {{ host.current_song.year }}
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xl font-semibold">
                            {{ host.current_song.title }}
                        </span>
                        <span class="text-sm text-navy/70">
                            {{ host.current_song.artist }}
                        </span>
                    </div>
                    <span
                        class="ml-auto flex items-center gap-1.5 text-xs text-amber-dark"
                    >
                        <Eye class="size-4" />Lösung nur hier sichtbar
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-card border border-navy/15 p-4">
                        <div class="text-xs text-navy/70">Abgaben</div>
                        <div class="text-[32px] font-semibold">
                            {{ submitted }}
                            <span class="text-base font-normal text-navy/70">
                                / {{ totalPlayers }}
                            </span>
                        </div>
                        <div class="mt-2 h-1.5 rounded-full bg-navy/5">
                            <div
                                class="h-full rounded-full bg-royal transition-[width]"
                                :style="{ width: `${submittedPercent}%` }"
                            />
                        </div>
                    </div>
                    <div class="rounded-card border border-navy/15 p-4">
                        <template v-if="host.reveal && state === 'REVEALED'">
                            <div class="text-xs text-navy/70">
                                Lagen richtig
                            </div>
                            <div class="text-[32px] font-semibold">
                                {{ host.reveal.percent_correct }} %
                            </div>
                        </template>
                        <template v-else>
                            <div class="text-xs text-navy/70">
                                Runde offen seit
                            </div>
                            <div class="text-[32px] font-semibold">
                                {{ openSince }}
                            </div>
                            <div class="mt-2 text-xs text-navy/70">
                                {{
                                    host.options.countdown_seconds
                                        ? `Countdown ${host.options.countdown_seconds} s`
                                        : 'Manuelles Schließen'
                                }}
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <button
                v-if="primary"
                type="button"
                :disabled="busy || (isLobby && !canStart)"
                class="flex h-24 items-center justify-center gap-3.5 rounded-card bg-royal text-[26px] font-semibold text-white transition-colors hover:bg-royal-hover disabled:opacity-50"
                @click="go()"
            >
                <component :is="primary.icon" class="size-7" />
                {{ primary.label }}
            </button>

            <div
                v-if="state === 'REVEALED' && !host.has_next_round"
                class="flex flex-col gap-3"
            >
                <div class="text-sm text-navy/70">
                    Das war das letzte Stück.
                    {{
                        host.tie_in_top_three
                            ? 'Auf dem Podium gibt es einen Gleichstand – Tiebreaker empfohlen.'
                            : 'Kein Gleichstand auf dem Podium.'
                    }}
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        type="button"
                        :disabled="busy || !host.has_tiebreaker_song"
                        class="flex h-24 items-center justify-center gap-3 rounded-card text-2xl font-semibold transition-colors disabled:opacity-50"
                        :class="
                            host.tie_in_top_three
                                ? 'bg-royal text-white hover:bg-royal-hover'
                                : 'border border-navy/15 hover:bg-navy/5'
                        "
                        @click="go('TIEBREAKER')"
                    >
                        Tiebreaker starten
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        class="flex h-24 items-center justify-center gap-3 rounded-card text-2xl font-semibold transition-colors"
                        :class="
                            host.tie_in_top_three
                                ? 'border border-navy/15 hover:bg-navy/5'
                                : 'bg-royal text-white hover:bg-royal-hover'
                        "
                        @click="go('FINISHED')"
                    >
                        <Trophy class="size-6" />Ergebnis zeigen
                    </button>
                </div>
            </div>

            <div
                v-if="state === 'ROUND_OPEN' || state === 'ROUND_CLOSED'"
                class="flex flex-wrap gap-2"
            >
                <Button
                    v-if="state === 'ROUND_CLOSED'"
                    variant="outline"
                    :disabled="busy"
                    title="Zu früh geschlossen? Abgaben bleiben erhalten und können geändert werden."
                    @click="run(() => api(reopen(host.game.id)))"
                >
                    <LockOpen />Runde wieder öffnen
                </Button>
                <Button
                    variant="outline"
                    :disabled="busy"
                    @click="run(() => api(skip(host.game.id)))"
                >
                    <FastForward />Runde überspringen
                </Button>
            </div>
        </div>

        <aside class="flex flex-col">
            <div class="px-5 pt-5 pb-2 text-sm font-semibold">Songliste</div>
            <div
                v-for="(song, index) in host.songs.round"
                :key="song.id"
                class="flex items-center gap-3 px-5 py-2"
                :class="{ 'bg-royal/5': song.status === 'running' }"
            >
                <span class="w-6 text-xs text-navy/70">{{ index + 1 }}</span>
                <span class="flex min-w-0 flex-col text-sm leading-snug">
                    <span
                        class="truncate"
                        :class="{ 'font-semibold': song.status === 'running' }"
                    >
                        {{ song.title }}
                    </span>
                    <span class="text-xs text-navy/70">
                        {{ song.artist }} · {{ song.year }}
                    </span>
                </span>
                <span
                    class="ml-auto text-xs whitespace-nowrap"
                    :class="
                        song.status === 'running'
                            ? 'text-alert'
                            : song.status === 'skipped'
                              ? 'text-navy/40'
                              : 'text-success'
                    "
                >
                    {{ statusText(song) }}
                </span>
            </div>
            <div
                v-if="!host.songs.round.length"
                class="px-5 py-2 text-sm text-navy/70"
            >
                Noch keine Runden-Songs.
            </div>

            <template v-if="!isLobby">
                <div class="px-5 pt-5 pb-2 text-sm font-semibold">Top 10</div>
                <div
                    v-for="row in host.top10"
                    :key="row.player_id"
                    class="flex items-center gap-3 px-5 py-1 text-sm"
                >
                    <span class="w-6 text-navy/70">{{ row.rank }}</span>
                    <span class="truncate">{{ row.name }}</span>
                    <span class="ml-auto font-semibold">{{ row.score }}</span>
                    <button
                        type="button"
                        class="text-navy/40 hover:text-navy"
                        title="Name ausblenden"
                        @click="
                            run(() =>
                                api(
                                    togglePlayer([host.game.id, row.player_id]),
                                ),
                            )
                        "
                    >
                        <EyeOff class="size-4" />
                    </button>
                </div>
            </template>
        </aside>
    </div>
</template>
