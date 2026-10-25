<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Copy,
    Eye,
    EyeOff,
    FastForward,
    FileSpreadsheet,
    GripVertical,
    Lock,
    Monitor,
    Play,
    RotateCcw,
    Trash2,
    Trophy,
    Users,
    ImagePlus,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    advance,
    reset,
    skip,
    removeInactivePlayers,
    removePlayer,
    togglePlayer,
    update,
} from '@/actions/App/Http/Controllers/Host/GameActionController';
import {
    destroy,
    importMethod,
    reorder,
    store,
} from '@/actions/App/Http/Controllers/Host/SongController';
import {
    destroySongImage,
    storeSongImage,
} from '@/actions/App/Http/Controllers/Host/BrandingController';
import BrandingEditor from '@/components/game/BrandingEditor.vue';
import JoinUrlEditor from '@/components/game/JoinUrlEditor.vue';
import LiveChip from '@/components/game/LiveChip.vue';
import PlayerPanel from '@/components/game/PlayerPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLive } from '@/composables/useLive';
import { api } from '@/lib/api';
import type {
    GameStateName,
    HostPlayer,
    HostProps,
    SongRow,
} from '@/types/game';

const props = defineProps<HostProps>();

const busy = ref(false);
const error = ref<string | null>(null);
const confirmReset = ref(false);
const submitted = ref(props.submitted);
const totalPlayers = ref(props.total_players);

watch(
    () => [props.submitted, props.total_players],
    () => {
        submitted.value = props.submitted;
        totalPlayers.value = props.total_players;
    },
);

const state = computed(() => props.state.state);
const isLobby = computed(() => state.value === 'LOBBY');

function reload() {
    router.reload();
}

let playerReload: number | undefined;

useLive({
    gameId: computed(() => props.game.id),
    hostChannel: true,
    handlers: { StateChanged: reload },
    hostHandlers: {
        SubmissionsCount: (payload: {
            submitted: number;
            total_players: number;
        }) => {
            submitted.value = payload.submitted;
            totalPlayers.value = payload.total_players;

            // The player list is refreshed at most every 5 s.
            if (!playerReload) {
                playerReload = window.setTimeout(() => {
                    playerReload = undefined;
                    router.reload({ only: ['players'] });
                }, 5000);
            }
        },
    },
    poll: reload,
});

async function run(action: () => Promise<unknown>) {
    busy.value = true;
    error.value = null;

    try {
        await action();
        router.reload();
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
        router.reload();
    } finally {
        busy.value = false;
    }
}

function go(to?: GameStateName) {
    return run(() =>
        api(advance(props.game.id), { from: state.value, to: to ?? null }),
    );
}

// --- Transition button ------------------------------------------------------

const primary = computed(() => {
    switch (state.value) {
        case 'LOBBY':
            return { label: 'Spiel starten', icon: Play };
        case 'ROUND_OPEN':
            return { label: 'Abgabe schließen', icon: Lock };
        case 'ROUND_CLOSED':
            return { label: 'Auflösen', icon: Eye };
        case 'REVEALED':
            return props.has_next_round
                ? { label: 'Nächstes Stück', icon: Play }
                : null;
        case 'TIEBREAKER':
            return { label: 'Ergebnis zeigen', icon: Trophy };
        default:
            return null;
    }
});

const stateChips: { value: GameStateName; label: string }[] = [
    { value: 'LOBBY', label: 'Lobby' },
    { value: 'ROUND_OPEN', label: 'Runde offen' },
    { value: 'ROUND_CLOSED', label: 'Geschlossen' },
    { value: 'REVEALED', label: 'Aufgelöst' },
    { value: 'TIEBREAKER', label: 'Tiebreaker' },
    { value: 'FINISHED', label: 'Beendet' },
];

const statusTitle = computed(() => {
    const round = `Stück ${props.state.round_number} von ${props.state.total_rounds}`;

    switch (state.value) {
        case 'ROUND_OPEN':
            return `${round} · Abgabe offen`;
        case 'ROUND_CLOSED':
            return `${round} · Abgabe geschlossen`;
        case 'REVEALED':
            return `${round} · ${props.state.round_skipped ? 'übersprungen' : 'aufgelöst'}`;
        case 'TIEBREAKER':
            return 'Tiebreaker läuft';
        case 'FINISHED':
            return 'Spiel beendet';
        default:
            return 'Lobby';
    }
});

const submittedPercent = computed(() =>
    totalPlayers.value
        ? Math.round((submitted.value / totalPlayers.value) * 100)
        : 0,
);

const now = ref(Date.now());
let clock: number | undefined;
let playerRefresh: number | undefined;
onMounted(() => {
    clock = window.setInterval(() => (now.value = Date.now()), 1000);
    // Activity changes without events (heartbeats), so refresh the list.
    playerRefresh = window.setInterval(
        () => router.reload({ only: ['players', 'rounds_played'] }),
        20000,
    );
});
onBeforeUnmount(() => {
    window.clearInterval(playerRefresh);
    window.clearInterval(clock);
    window.clearTimeout(playerReload);
});

const openSince = computed(() => {
    if (!props.round_opened_at) {
        return '–';
    }

    const seconds = Math.max(
        0,
        Math.floor(
            (now.value - new Date(props.round_opened_at).getTime()) / 1000,
        ),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

// --- Preparation ------------------------------------------------------------

type Tab = 'round' | 'start_pool' | 'tiebreaker';
const tab = ref<Tab>('round');
const tabs: { value: Tab; label: string; role: SongRow['role'] }[] = [
    { value: 'round', label: 'Runden-Songs', role: 'ROUND' },
    { value: 'start_pool', label: 'Startkarten-Pool', role: 'START_POOL' },
    { value: 'tiebreaker', label: 'Tiebreaker', role: 'TIEBREAKER' },
];

const orderedSongs = ref<SongRow[]>([]);
watch(
    () => props.songs[tab.value],
    (songs) => (orderedSongs.value = [...songs]),
    { immediate: true },
);

const newSong = ref({ title: '', artist: '', year: '' });
const gameName = ref(props.game.name);
const countdownSeconds = ref(props.options.countdown_seconds ?? 30);
const fileInput = ref<HTMLInputElement | null>(null);
const replaceOnImport = ref(false);

function addSong() {
    const role = tabs.find((t) => t.value === tab.value)!.role;

    return run(async () => {
        await api(store(props.game.id), {
            ...newSong.value,
            year: Number(newSong.value.year),
            role,
        });
        newSong.value = { title: '', artist: '', year: '' };
    });
}

function removeSong(song: SongRow) {
    return run(() => api(destroy([props.game.id, song.id])));
}

function importCsv(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    const data = new FormData();
    data.append('file', file);
    data.append('replace', replaceOnImport.value ? '1' : '0');

    return run(async () => {
        await api(importMethod(props.game.id), data);

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    });
}

function saveSettings(options: Record<string, unknown> = {}) {
    return run(() =>
        api(update(props.game.id), {
            name: gameName.value,
            options: { ...props.options, ...options },
        }),
    );
}

// Drag & drop ordering of the round songs
const dragIndex = ref<number | null>(null);

function onDragOver(index: number) {
    if (dragIndex.value === null || dragIndex.value === index) {
        return;
    }

    const [moved] = orderedSongs.value.splice(dragIndex.value, 1);
    orderedSongs.value.splice(index, 0, moved);
    dragIndex.value = index;
}

function onDrop() {
    dragIndex.value = null;
    const ids = [
        ...props.songs.round,
        ...props.songs.start_pool,
        ...props.songs.tiebreaker,
    ]
        .filter((song) => song.role !== 'ROUND')
        .map((song) => song.id);

    return run(() =>
        api(reorder(props.game.id), {
            ids: [...orderedSongs.value.map((song) => song.id), ...ids],
        }),
    );
}

function copyHostLink() {
    void navigator.clipboard?.writeText(props.host_url);
}

function uploadSongImage(song: SongRow, event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    const data = new FormData();
    data.append('file', file);

    return run(async () => {
        await api(storeSongImage([props.game.id, song.id]), data);
        input.value = '';
    });
}

function removeOne(player: HostPlayer) {
    return run(() => api(removePlayer([props.game.id, player.id])));
}

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
    <Head :title="`Moderation · ${game.name}`" />

    <div class="min-h-dvh bg-[#e9ebef] text-navy">
        <div
            class="mx-auto max-w-300 overflow-hidden bg-white md:my-6 md:rounded-card md:border md:border-navy/15"
        >
            <!-- Header -->
            <header
                class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-2 bg-navy px-6 py-3 text-white"
            >
                <span class="text-base font-semibold">Konzert-Hitster</span>
                <span class="text-sm text-white/50">
                    {{ game.name }} · Moderator
                </span>
                <span class="ml-auto flex items-center gap-5 text-sm">
                    <span class="flex items-center gap-1.5">
                        <Users class="size-4 text-white/50" />
                        {{ totalPlayers }} Spieler
                    </span>
                    <span class="text-white/50">Code {{ game.code }}</span>
                    <a
                        :href="screen_url"
                        target="_blank"
                        class="flex items-center gap-1.5 text-white/75 hover:text-white"
                    >
                        <Monitor class="size-4" />Leinwand
                    </a>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 text-white/75 hover:text-white"
                        title="Moderator-Link kopieren (enthält den geheimen Schlüssel)"
                        @click="copyHostLink"
                    >
                        <Copy class="size-4" />Link
                    </button>
                </span>
            </header>

            <!-- State chips -->
            <div
                class="flex flex-wrap gap-1.5 border-b border-navy/15 bg-navy/5 px-6 py-4"
            >
                <span
                    v-for="chip in stateChips"
                    :key="chip.value"
                    class="inline-flex h-7 items-center rounded-full border px-3 text-xs font-semibold"
                    :class="
                        chip.value === state
                            ? 'border-royal bg-royal text-white'
                            : 'border-navy/15 bg-white text-navy/70'
                    "
                >
                    {{ chip.label }}
                </span>
            </div>

            <div
                v-if="error"
                class="border-b border-alert/30 bg-alert/10 px-6 py-3 text-sm text-alert"
            >
                {{ error }}
            </div>

            <!-- 3b Preparation (lobby) -->
            <div v-if="isLobby" class="grid md:grid-cols-[minmax(0,1fr)_340px]">
                <div class="flex flex-col gap-4 border-navy/15 p-6 md:border-r">
                    <div class="flex flex-col gap-1.5">
                        <label for="game-name" class="text-xs text-navy/70">
                            Name des Spiels
                        </label>
                        <input
                            id="game-name"
                            v-model="gameName"
                            class="h-10 rounded-btn border border-navy/15 px-3 text-sm outline-none focus:border-royal"
                            @change="saveSettings()"
                        />
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-x-6 border-b border-navy/15"
                    >
                        <button
                            v-for="t in tabs"
                            :key="t.value"
                            type="button"
                            class="-mb-px border-b-2 py-2.5 text-sm"
                            :class="
                                tab === t.value
                                    ? 'border-royal font-semibold text-royal'
                                    : 'border-transparent text-navy/70'
                            "
                            @click="tab = t.value"
                        >
                            {{ t.label }} ({{ songs[t.value].length }})
                        </button>
                        <label
                            class="ml-auto flex cursor-pointer items-center gap-1.5 rounded-btn border border-navy/15 px-3 py-1.5 text-xs font-semibold hover:bg-navy/5"
                        >
                            <FileSpreadsheet class="size-4" />
                            CSV importieren
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".csv,text/csv,text/plain"
                                class="hidden"
                                @change="importCsv"
                            />
                        </label>
                    </div>
                    <label
                        class="-mt-2 flex items-center gap-2 self-end text-xs text-navy/70"
                    >
                        <input v-model="replaceOnImport" type="checkbox" />
                        Beim Import bestehende Songs ersetzen
                    </label>

                    <div
                        class="flex flex-col overflow-hidden rounded-card border border-navy/15"
                    >
                        <div
                            class="grid grid-cols-[32px_32px_44px_minmax(0,1fr)_minmax(0,1fr)_64px_32px] gap-2 bg-navy/70 px-3 py-2 text-xs text-white"
                        >
                            <span /><span>#</span><span>Foto</span
                            ><span>Titel</span> <span>Interpret</span
                            ><span>Jahr</span><span />
                        </div>
                        <div
                            v-for="(song, index) in orderedSongs"
                            :key="song.id"
                            :draggable="tab === 'round'"
                            class="grid h-11 grid-cols-[32px_32px_44px_minmax(0,1fr)_minmax(0,1fr)_64px_32px] items-center gap-2 border-t border-navy/15 px-3 text-sm"
                            :class="[
                                index % 2 ? 'bg-royal/5' : 'bg-white',
                                dragIndex === index ? 'opacity-50' : '',
                            ]"
                            @dragstart="dragIndex = index"
                            @dragover.prevent="onDragOver(index)"
                            @drop.prevent="onDrop"
                            @dragend="dragIndex = null"
                        >
                            <GripVertical
                                class="size-4"
                                :class="
                                    tab === 'round'
                                        ? 'cursor-grab text-navy/40'
                                        : 'text-transparent'
                                "
                            />
                            <span class="text-navy/70">{{ index + 1 }}</span>
                            <span class="group relative size-9">
                                <label
                                    class="flex size-9 cursor-pointer items-center justify-center overflow-hidden rounded-md border border-navy/15 bg-white text-navy/40 hover:border-royal hover:text-royal"
                                    :title="
                                        song.image
                                            ? 'Foto ersetzen'
                                            : 'Foto hinzufügen'
                                    "
                                >
                                    <img
                                        v-if="song.image"
                                        :src="song.image.sm"
                                        alt=""
                                        class="size-full object-cover"
                                    />
                                    <ImagePlus v-else class="size-4" />
                                    <input
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        class="hidden"
                                        @change="uploadSongImage(song, $event)"
                                    />
                                </label>
                                <button
                                    v-if="song.image"
                                    type="button"
                                    class="absolute -top-1.5 -right-1.5 hidden size-4 items-center justify-center rounded-full bg-alert text-white group-hover:flex"
                                    title="Foto entfernen"
                                    @click="
                                        run(() =>
                                            api(
                                                destroySongImage([
                                                    game.id,
                                                    song.id,
                                                ]),
                                            ),
                                        )
                                    "
                                >
                                    <X class="size-3" />
                                </button>
                            </span>
                            <span class="truncate">{{ song.title }}</span>
                            <span class="truncate text-navy/70">
                                {{ song.artist }}
                            </span>
                            <span class="font-semibold">{{ song.year }}</span>
                            <button
                                type="button"
                                class="text-navy/40 hover:text-alert"
                                :aria-label="`${song.title} löschen`"
                                @click="removeSong(song)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                        <form
                            class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_80px_auto] gap-2 border-t border-navy/15 bg-navy/5 p-2"
                            @submit.prevent="addSong"
                        >
                            <input
                                v-model="newSong.title"
                                required
                                placeholder="Titel"
                                class="h-9 rounded-btn border border-navy/15 bg-white px-2 text-sm"
                            />
                            <input
                                v-model="newSong.artist"
                                required
                                placeholder="Interpret"
                                class="h-9 rounded-btn border border-navy/15 bg-white px-2 text-sm"
                            />
                            <input
                                v-model="newSong.year"
                                required
                                inputmode="numeric"
                                placeholder="Jahr"
                                class="h-9 rounded-btn border border-navy/15 bg-white px-2 text-sm"
                            />
                            <Button type="submit" size="sm" :disabled="busy">
                                Hinzufügen
                            </Button>
                        </form>
                    </div>
                    <div class="text-xs text-navy/70">
                        CSV-Format: titel;interpret;jahr;rolle (rolle: runde,
                        start, tiebreaker) · Maßgeblich ist das
                        Original-Erscheinungsjahr.
                    </div>
                </div>

                <aside class="flex flex-col gap-5 bg-navy/5 p-6">
                    <button
                        type="button"
                        :disabled="busy"
                        class="flex h-20 items-center justify-center gap-3 rounded-card bg-royal text-2xl font-semibold text-white transition-colors hover:bg-royal-hover disabled:opacity-60"
                        @click="go()"
                    >
                        <Play class="size-6" />Spiel starten
                    </button>
                    <div class="text-xs text-navy/70">
                        Startet Stück 1. Beitreten ist auch während des Spiels
                        möglich.
                    </div>

                    <div class="text-sm font-semibold">Optionen</div>
                    <div
                        class="flex flex-col gap-4 rounded-card border border-navy/15 bg-white p-4"
                    >
                        <label class="flex items-center gap-3">
                            <span class="flex-1">
                                <span class="block text-sm">
                                    Bonusfrage pro Runde
                                </span>
                                <span class="block text-xs text-navy/70">
                                    Multiple Choice, +1 Bonuspunkt
                                </span>
                            </span>
                            <input
                                type="checkbox"
                                class="toggle"
                                :checked="options.bonus_question"
                                @change="
                                    saveSettings({
                                        bonus_question: (
                                            $event.target as HTMLInputElement
                                        ).checked,
                                    })
                                "
                            />
                        </label>
                        <label class="flex items-center gap-3">
                            <span class="flex-1">
                                <span class="block text-sm">Countdown</span>
                                <span class="block text-xs text-navy/70">
                                    Schließt automatisch nach
                                    <input
                                        v-model.number="countdownSeconds"
                                        type="number"
                                        min="10"
                                        max="600"
                                        class="w-12 rounded border border-navy/15 px-1"
                                        @change="
                                            options.countdown_seconds &&
                                            saveSettings({
                                                countdown_seconds:
                                                    countdownSeconds,
                                            })
                                        "
                                    />
                                    s
                                </span>
                            </span>
                            <input
                                type="checkbox"
                                class="toggle"
                                :checked="options.countdown_seconds !== null"
                                @change="
                                    saveSettings({
                                        countdown_seconds: (
                                            $event.target as HTMLInputElement
                                        ).checked
                                            ? countdownSeconds
                                            : null,
                                    })
                                "
                            />
                        </label>
                    </div>

                    <div class="text-sm font-semibold">Tiebreaker-Song</div>
                    <div
                        class="flex items-center gap-3 rounded-card border border-navy/15 bg-white p-4"
                    >
                        <template v-if="songs.tiebreaker[0]">
                            <span class="text-xl font-semibold">
                                {{ songs.tiebreaker[0].year }}
                            </span>
                            <span class="flex flex-col text-sm">
                                <span>{{ songs.tiebreaker[0].title }}</span>
                                <span class="text-xs text-navy/70">
                                    {{ songs.tiebreaker[0].artist }}
                                </span>
                            </span>
                        </template>
                        <span v-else class="text-sm text-navy/70">
                            Noch keiner festgelegt (Tab „Tiebreaker“).
                        </span>
                    </div>

                    <div class="text-xs text-navy/70">
                        Namen mit Begriffen aus der Wortfilterliste werden beim
                        Beitritt abgelehnt.
                    </div>
                </aside>
            </div>

            <!-- 3a Live control -->
            <div v-else class="grid md:grid-cols-[minmax(0,1fr)_360px]">
                <div class="flex flex-col gap-5 border-navy/15 p-6 md:border-r">
                    <div class="flex items-center gap-3">
                        <LiveChip v-if="state === 'ROUND_OPEN'" />
                        <span class="text-xl font-semibold">
                            {{ statusTitle }}
                        </span>
                    </div>

                    <div
                        v-if="
                            current_song &&
                            state !== 'FINISHED' &&
                            state !== 'TIEBREAKER'
                        "
                        class="flex flex-wrap items-center gap-5 rounded-card border border-navy/15 bg-amber-soft p-5"
                    >
                        <img
                            v-if="current_song.image"
                            :src="current_song.image.sm"
                            alt=""
                            class="size-16 rounded-card object-cover"
                        />
                        <div class="text-5xl leading-none font-semibold">
                            {{ current_song.year }}
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xl font-semibold">
                                {{ current_song.title }}
                            </span>
                            <span class="text-sm text-navy/70">
                                {{ current_song.artist }}
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
                                <span
                                    class="text-base font-normal text-navy/70"
                                >
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
                            <template v-if="reveal && state === 'REVEALED'">
                                <div class="text-xs text-navy/70">
                                    Lagen richtig
                                </div>
                                <div class="text-[32px] font-semibold">
                                    {{ reveal.percent_correct }} %
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
                                        options.countdown_seconds
                                            ? `Countdown ${options.countdown_seconds} s`
                                            : 'Manuelles Schließen'
                                    }}
                                </div>
                            </template>
                        </div>
                    </div>

                    <button
                        v-if="primary"
                        type="button"
                        :disabled="busy"
                        class="flex h-24 items-center justify-center gap-3.5 rounded-card bg-royal text-[26px] font-semibold text-white transition-colors hover:bg-royal-hover disabled:opacity-60"
                        @click="go()"
                    >
                        <component :is="primary.icon" class="size-7" />
                        {{ primary.label }}
                    </button>

                    <div
                        v-if="state === 'REVEALED' && !has_next_round"
                        class="flex flex-col gap-3"
                    >
                        <div class="text-sm text-navy/70">
                            Das war das letzte Stück.
                            {{
                                tie_in_top_three
                                    ? 'Auf dem Podium gibt es einen Gleichstand – Tiebreaker empfohlen.'
                                    : 'Kein Gleichstand auf dem Podium.'
                            }}
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button
                                type="button"
                                :disabled="busy || !has_tiebreaker_song"
                                class="flex h-24 items-center justify-center gap-3 rounded-card text-2xl font-semibold transition-colors disabled:opacity-50"
                                :class="
                                    tie_in_top_three
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
                                    tie_in_top_three
                                        ? 'border border-navy/15 hover:bg-navy/5'
                                        : 'bg-royal text-white hover:bg-royal-hover'
                                "
                                @click="go('FINISHED')"
                            >
                                <Trophy class="size-6" />Ergebnis zeigen
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-between gap-2">
                        <Button
                            v-if="
                                state === 'ROUND_OPEN' ||
                                state === 'ROUND_CLOSED'
                            "
                            variant="outline"
                            :disabled="busy"
                            @click="run(() => api(skip(game.id)))"
                        >
                            <FastForward />Runde überspringen
                        </Button>
                        <span v-else />
                        <Button
                            variant="ghost"
                            class="text-alert hover:text-alert"
                            @click="confirmReset = true"
                        >
                            <RotateCcw />Spiel zurücksetzen
                        </Button>
                    </div>
                </div>

                <div class="flex flex-col">
                    <div class="px-5 pt-5 pb-2 text-sm font-semibold">
                        Songliste
                    </div>
                    <div
                        v-for="(song, index) in songs.round"
                        :key="song.id"
                        class="flex items-center gap-3 px-5 py-2"
                        :class="{ 'bg-royal/5': song.status === 'running' }"
                    >
                        <span class="w-6 text-xs text-navy/70">{{
                            index + 1
                        }}</span>
                        <span
                            class="flex min-w-0 flex-col text-sm leading-snug"
                        >
                            <span
                                class="truncate"
                                :class="{
                                    'font-semibold': song.status === 'running',
                                }"
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

                    <div class="px-5 pt-5 pb-2 text-sm font-semibold">
                        Top 10
                    </div>
                    <div
                        v-for="row in top10"
                        :key="row.player_id"
                        class="flex items-center gap-3 px-5 py-1 text-sm"
                    >
                        <span class="w-6 text-navy/70">{{ row.rank }}</span>
                        <span class="truncate">{{ row.name }}</span>
                        <span class="ml-auto font-semibold">{{
                            row.score
                        }}</span>
                        <button
                            type="button"
                            class="text-navy/40 hover:text-navy"
                            title="Name ausblenden"
                            @click="
                                run(() =>
                                    api(togglePlayer([game.id, row.player_id])),
                                )
                            "
                        >
                            <EyeOff class="size-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="grid gap-8 border-t border-navy/15 p-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
            >
                <PlayerPanel
                    :players="players"
                    :rounds-played="rounds_played"
                    :inactive-after-seconds="inactive_after_seconds"
                    :busy="busy"
                    @toggle-hidden="
                        (player) =>
                            run(() => api(togglePlayer([game.id, player.id])))
                    "
                    @remove="removeOne"
                    @remove-inactive="
                        run(() => api(removeInactivePlayers(game.id)))
                    "
                />
                <div class="flex flex-col gap-8">
                    <JoinUrlEditor
                        :game-id="game.id"
                        :code="game.code"
                        :join-url="options.join_url"
                        :default-join-url="default_join_url"
                        :current-join-url="join_url"
                        @changed="router.reload()"
                    />
                    <BrandingEditor
                        :game-id="game.id"
                        :branding="branding"
                        @changed="router.reload()"
                    />
                </div>
            </div>
        </div>

        <Dialog v-model:open="confirmReset">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Spiel zurücksetzen?</DialogTitle>
                    <DialogDescription>
                        Alle Runden, Abgaben und Tiebreaker-Antworten werden
                        gelöscht. Die Spieler und ihre Startkarten bleiben
                        erhalten, das Spiel startet wieder in der Lobby.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Abbrechen</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        @click="
                            confirmReset = false;
                            run(() => api(reset(game.id)));
                        "
                    >
                        Zurücksetzen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
.toggle {
    appearance: none;
    position: relative;
    width: 40px;
    height: 22px;
    flex-shrink: 0;
    border-radius: 11px;
    background: rgba(11, 32, 65, 0.15);
    cursor: pointer;
    transition: background var(--ease-standard) 0.15s;
}

.toggle::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    border-radius: 9px;
    background: #fff;
    transition: transform 0.15s var(--ease-standard);
}

.toggle:checked {
    background: #1967e6;
}

.toggle:checked::after {
    transform: translateX(18px);
}
</style>
