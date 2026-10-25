<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Copy, Monitor, Music, Play, Settings, Users } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    removeInactivePlayers,
    removePlayer,
    reset,
    togglePlayer,
} from '@/actions/App/Http/Controllers/Host/GameActionController';
import PlayerPanel from '@/components/game/PlayerPanel.vue';
import ControlTab from '@/components/host/ControlTab.vue';
import SettingsTab from '@/components/host/SettingsTab.vue';
import SongsTab from '@/components/host/SongsTab.vue';
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
import type { GameStateName, HostPlayer, HostProps } from '@/types/game';

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

// --- Tabs -------------------------------------------------------------------

type Tab = 'steuerung' | 'spieler' | 'songs' | 'einstellungen';
const tabIds: Tab[] = ['steuerung', 'spieler', 'songs', 'einstellungen'];

function initialTab(): Tab {
    const fromHash = window.location.hash.slice(1) as Tab;

    if (tabIds.includes(fromHash)) {
        return fromHash;
    }

    // An empty game starts with the preparation of the songs.
    return isLobby.value && props.songs.round.length === 0
        ? 'songs'
        : 'steuerung';
}

const tab = ref<Tab>(initialTab());

watch(tab, (value) => history.replaceState(history.state, '', `#${value}`));

function openTab(target: 'songs' | 'settings') {
    tab.value = target === 'songs' ? 'songs' : 'einstellungen';
}

// Activity of the players for the tab badge (same rule as the player list)
const now = ref(Date.now());
const inactiveCount = computed(
    () =>
        props.players.filter(
            (player: HostPlayer) =>
                !player.last_seen_at ||
                now.value - new Date(player.last_seen_at).getTime() >=
                    props.inactive_after_seconds * 1000,
        ).length,
);

const tabs = computed(() => [
    {
        id: 'steuerung' as const,
        label: 'Steuerung',
        icon: Play,
        badge: state.value === 'ROUND_OPEN' ? 'live' : null,
    },
    {
        id: 'spieler' as const,
        label: 'Spieler',
        icon: Users,
        badge: `${totalPlayers.value}${inactiveCount.value ? ` · ${inactiveCount.value} inaktiv` : ''}`,
    },
    {
        id: 'songs' as const,
        label: 'Songs',
        icon: Music,
        badge: String(props.songs.round.length),
    },
    {
        id: 'einstellungen' as const,
        label: 'Einstellungen',
        icon: Settings,
        badge: null,
    },
]);

// --- Live updates -----------------------------------------------------------

function reload() {
    router.reload();
}

let playerReload: number | undefined;
let playerRefresh: number | undefined;
let clock: number | undefined;

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

onMounted(() => {
    clock = window.setInterval(() => (now.value = Date.now()), 10000);
    // Activity changes without events (heartbeats), so refresh the list.
    playerRefresh = window.setInterval(
        () => router.reload({ only: ['players', 'rounds_played'] }),
        20000,
    );
});
onBeforeUnmount(() => {
    window.clearInterval(clock);
    window.clearInterval(playerRefresh);
    window.clearTimeout(playerReload);
});

async function run(action: () => Promise<unknown>) {
    busy.value = true;
    error.value = null;

    try {
        await action();
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
        router.reload();
    }
}

const stateChips: { value: GameStateName; label: string }[] = [
    { value: 'LOBBY', label: 'Lobby' },
    { value: 'ROUND_OPEN', label: 'Runde offen' },
    { value: 'ROUND_CLOSED', label: 'Geschlossen' },
    { value: 'REVEALED', label: 'Aufgelöst' },
    { value: 'TIEBREAKER', label: 'Tiebreaker' },
    { value: 'FINISHED', label: 'Beendet' },
];

function copyHostLink() {
    void navigator.clipboard?.writeText(props.host_url);
}
</script>

<template>
    <Head :title="`Moderation · ${game.name}`" />

    <div class="min-h-dvh bg-[#e9ebef] text-navy">
        <div
            class="mx-auto max-w-300 overflow-hidden bg-white md:my-6 md:rounded-card md:border md:border-navy/15"
        >
            <header
                class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-2 bg-navy px-6 py-3 text-white"
            >
                <span class="text-base font-semibold">{{
                    branding.title
                }}</span>
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

            <div
                class="flex flex-wrap gap-1.5 border-b border-navy/15 bg-navy/5 px-6 py-3"
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

            <nav
                class="flex gap-1 overflow-x-auto border-b border-navy/15 px-4"
                role="tablist"
            >
                <button
                    v-for="t in tabs"
                    :key="t.id"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.id"
                    class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-3 text-sm whitespace-nowrap transition-colors"
                    :class="
                        tab === t.id
                            ? 'border-royal font-semibold text-royal'
                            : 'border-transparent text-navy/70 hover:text-navy'
                    "
                    @click="tab = t.id"
                >
                    <component :is="t.icon" class="size-4" />
                    {{ t.label }}
                    <span
                        v-if="t.badge === 'live'"
                        class="size-2 animate-pulse rounded-full bg-alert"
                        title="Runde offen"
                    />
                    <span
                        v-else-if="t.badge"
                        class="rounded-full bg-navy/5 px-2 py-0.5 text-xs font-normal text-navy/70"
                    >
                        {{ t.badge }}
                    </span>
                </button>
            </nav>

            <div
                v-if="error"
                class="border-b border-alert/30 bg-alert/10 px-6 py-3 text-sm text-alert"
            >
                {{ error }}
            </div>

            <ControlTab
                v-if="tab === 'steuerung'"
                :host="props"
                :submitted="submitted"
                :total-players="totalPlayers"
                :busy="busy"
                :run="run"
                @open-tab="openTab"
            />

            <div v-else-if="tab === 'spieler'" class="p-6">
                <PlayerPanel
                    :game-id="game.id"
                    :players="players"
                    :rounds-played="rounds_played"
                    :inactive-after-seconds="inactive_after_seconds"
                    :busy="busy"
                    @toggle-hidden="
                        (player) =>
                            run(() => api(togglePlayer([game.id, player.id])))
                    "
                    @remove="
                        (player) =>
                            run(() => api(removePlayer([game.id, player.id])))
                    "
                    @renamed="router.reload({ only: ['players', 'top10'] })"
                    @remove-inactive="
                        run(() => api(removeInactivePlayers(game.id)))
                    "
                />
            </div>

            <SongsTab
                v-else-if="tab === 'songs'"
                :game-id="game.id"
                :songs="songs"
                :editable="isLobby"
                :busy="busy"
                :run="run"
            />

            <SettingsTab
                v-else
                :host="props"
                :busy="busy"
                :run="run"
                @changed="router.reload()"
                @reset="confirmReset = true"
            />
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
