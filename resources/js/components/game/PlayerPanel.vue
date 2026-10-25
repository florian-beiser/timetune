<script setup lang="ts">
import { Eye, EyeOff, Search, Trash2, UserX } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
import type { HostPlayer } from '@/types/game';

const props = defineProps<{
    players: HostPlayer[];
    roundsPlayed: number;
    inactiveAfterSeconds: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    toggleHidden: [player: HostPlayer];
    remove: [player: HostPlayer];
    removeInactive: [];
}>();

type Filter = 'all' | 'active' | 'inactive';
const filter = ref<Filter>('all');
const search = ref('');
const confirm = ref<{ player: HostPlayer | null } | null>(null);

const now = ref(Date.now());
let clock: number | undefined;
onMounted(
    () => (clock = window.setInterval(() => (now.value = Date.now()), 10000)),
);
onBeforeUnmount(() => window.clearInterval(clock));

function secondsSinceSeen(player: HostPlayer): number | null {
    return player.last_seen_at
        ? Math.max(
              0,
              (now.value - new Date(player.last_seen_at).getTime()) / 1000,
          )
        : null;
}

function isActive(player: HostPlayer): boolean {
    const seconds = secondsSinceSeen(player);

    return seconds !== null && seconds < props.inactiveAfterSeconds;
}

function activityText(player: HostPlayer): string {
    const seconds = secondsSinceSeen(player);

    if (seconds === null) {
        return 'nie verbunden';
    }

    if (seconds < props.inactiveAfterSeconds) {
        return 'aktiv';
    }

    const minutes = Math.floor(seconds / 60);

    return minutes < 60
        ? `inaktiv seit ${minutes} Min.`
        : `inaktiv seit ${Math.floor(minutes / 60)} Std.`;
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

const inactiveCount = computed(
    () => props.players.filter((player) => !isActive(player)).length,
);

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.players.filter(
        (player) =>
            (filter.value === 'all' ||
                (filter.value === 'active') === isActive(player)) &&
            (term === '' || player.name.toLowerCase().includes(term)),
    );
});

const filters = computed<{ value: Filter; label: string }[]>(() => [
    { value: 'all', label: `Alle (${props.players.length})` },
    {
        value: 'active',
        label: `Aktiv (${props.players.length - inactiveCount.value})`,
    },
    { value: 'inactive', label: `Inaktiv (${inactiveCount.value})` },
]);

function confirmRemoval() {
    const player = confirm.value?.player;
    confirm.value = null;

    if (player) {
        emit('remove', player);
    } else {
        emit('removeInactive');
    }
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-sm font-semibold">Angemeldete Spieler</h2>
            <div class="flex gap-1">
                <button
                    v-for="option in filters"
                    :key="option.value"
                    type="button"
                    class="h-7 rounded-full border px-3 text-xs font-semibold"
                    :class="
                        filter === option.value
                            ? 'border-royal bg-royal text-white'
                            : 'border-navy/15 text-navy/70 hover:bg-navy/5'
                    "
                    @click="filter = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
            <label
                class="flex h-8 items-center gap-2 rounded-btn border border-navy/15 px-2 text-sm focus-within:border-royal"
            >
                <Search class="size-4 text-navy/40" />
                <span class="sr-only">Spieler suchen</span>
                <input
                    v-model="search"
                    placeholder="Name suchen"
                    class="w-36 outline-none"
                />
            </label>
            <Button
                variant="outline"
                size="sm"
                class="ml-auto text-alert hover:text-alert"
                :disabled="busy || inactiveCount === 0"
                @click="confirm = { player: null }"
            >
                <UserX />Inaktive entfernen ({{ inactiveCount }})
            </Button>
        </div>

        <div class="max-h-105 overflow-auto rounded-card border border-navy/15">
            <table class="w-full text-sm">
                <thead
                    class="sticky top-0 bg-navy/70 text-left text-xs text-white"
                >
                    <tr>
                        <th class="px-3 py-2 font-normal">Name</th>
                        <th class="px-3 py-2 font-normal">Status</th>
                        <th class="px-3 py-2 font-normal">Dabei seit</th>
                        <th class="px-3 py-2 text-right font-normal">
                            Abgaben
                        </th>
                        <th class="px-3 py-2 text-right font-normal">Karten</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(player, index) in visible"
                        :key="player.id"
                        class="border-t border-navy/10"
                        :class="index % 2 ? 'bg-royal/5' : 'bg-white'"
                    >
                        <td class="max-w-48 truncate px-3 py-1.5">
                            <span
                                :class="{
                                    'text-navy/40 line-through':
                                        player.is_hidden,
                                }"
                            >
                                {{ player.name }}
                            </span>
                        </td>
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            <span class="flex items-center gap-1.5">
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        isActive(player)
                                            ? 'bg-success'
                                            : 'bg-navy/30'
                                    "
                                />
                                <span
                                    :class="
                                        isActive(player)
                                            ? 'text-success'
                                            : 'text-navy/70'
                                    "
                                >
                                    {{ activityText(player) }}
                                </span>
                            </span>
                        </td>
                        <td class="px-3 py-1.5 text-navy/70">
                            {{ time(player.joined_at) }}
                        </td>
                        <td class="px-3 py-1.5 text-right text-navy/70">
                            {{ player.submissions }} / {{ roundsPlayed }}
                        </td>
                        <td class="px-3 py-1.5 text-right font-semibold">
                            {{ player.score }}
                        </td>
                        <td class="px-3 py-1.5">
                            <span class="flex justify-end gap-3">
                                <button
                                    type="button"
                                    class="text-navy/40 hover:text-navy"
                                    :title="
                                        player.is_hidden
                                            ? 'Name wieder anzeigen'
                                            : 'Name ausblenden'
                                    "
                                    @click="emit('toggleHidden', player)"
                                >
                                    <Eye
                                        v-if="player.is_hidden"
                                        class="size-4"
                                    />
                                    <EyeOff v-else class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="text-navy/40 hover:text-alert"
                                    title="Aus dem Spiel entfernen"
                                    @click="confirm = { player }"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!visible.length">
                        <td colspan="6" class="px-3 py-4 text-navy/70">
                            {{
                                players.length
                                    ? 'Keine Spieler für diesen Filter.'
                                    : 'Noch niemand beigetreten.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-navy/70">
            Inaktiv = seit {{ Math.round(inactiveAfterSeconds / 60) }} Min. kein
            Lebenszeichen vom Handy (Seite geschlossen oder Handy gesperrt).
            Entfernte Spieler verlieren ihre Karten und können neu beitreten.
        </p>

        <Dialog
            :open="confirm !== null"
            @update:open="(open) => !open && (confirm = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            confirm?.player
                                ? `${confirm.player.name} entfernen?`
                                : `${inactiveCount} inaktive Spieler entfernen?`
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Zeitleiste und Abgaben werden gelöscht. Auf dem Handy
                        erscheint ein Hinweis, ein erneuter Beitritt ist
                        möglich.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Abbrechen</Button>
                    </DialogClose>
                    <Button variant="destructive" @click="confirmRemoval">
                        Entfernen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
