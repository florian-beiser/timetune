<script setup lang="ts">
import {
    FileSpreadsheet,
    GripVertical,
    ImagePlus,
    Lock,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    destroySongImage,
    storeSongImage,
} from '@/actions/App/Http/Controllers/Host/BrandingController';
import {
    destroy,
    importMethod,
    reorder,
    store,
} from '@/actions/App/Http/Controllers/Host/SongController';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import type { HostProps, SongRow } from '@/types/game';

const props = defineProps<{
    gameId: number;
    songs: HostProps['songs'];
    /** Songs can only be changed in the lobby; photos always. */
    editable: boolean;
    busy: boolean;
    run: (action: () => Promise<unknown>) => Promise<void>;
}>();

type List = 'round' | 'start_pool' | 'tiebreaker';
const list = ref<List>('round');
const lists: {
    value: List;
    label: string;
    role: SongRow['role'];
    hint: string;
}[] = [
    {
        value: 'round',
        label: 'Runden-Songs',
        role: 'ROUND',
        hint: 'Die Stücke, die gespielt werden, in Spielreihenfolge (per Drag & Drop sortieren).',
    },
    {
        value: 'start_pool',
        label: 'Startkarten-Pool',
        role: 'START_POOL',
        hint: 'Songs, die nicht gespielt werden. Jeder Spieler bekommt eine davon als aufgedeckte Startkarte.',
    },
    {
        value: 'tiebreaker',
        label: 'Tiebreaker',
        role: 'TIEBREAKER',
        hint: 'Ein Song für die Schätzfrage bei Gleichstand. Es zählt der erste.',
    },
];
const current = computed(() => lists.find((l) => l.value === list.value)!);

const ordered = ref<SongRow[]>([]);
watch(
    () => props.songs[list.value],
    (songs) => (ordered.value = [...songs]),
    { immediate: true },
);

const newSong = ref({ title: '', artist: '', year: '' });
const fileInput = ref<HTMLInputElement | null>(null);
const replaceOnImport = ref(false);

function addSong() {
    return props.run(async () => {
        await api(store(props.gameId), {
            ...newSong.value,
            year: Number(newSong.value.year),
            role: current.value.role,
        });
        newSong.value = { title: '', artist: '', year: '' };
    });
}

function importCsv(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    const data = new FormData();
    data.append('file', file);
    data.append('replace', replaceOnImport.value ? '1' : '0');

    return props.run(async () => {
        await api(importMethod(props.gameId), data);

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    });
}

function uploadImage(song: SongRow, event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    const data = new FormData();
    data.append('file', file);

    return props.run(async () => {
        await api(storeSongImage([props.gameId, song.id]), data);
        input.value = '';
    });
}

// Drag & drop ordering of the round songs
const dragIndex = ref<number | null>(null);
const canDrag = computed(() => props.editable && list.value === 'round');

function onDragOver(index: number) {
    if (dragIndex.value === null || dragIndex.value === index) {
        return;
    }

    const [moved] = ordered.value.splice(dragIndex.value, 1);
    ordered.value.splice(index, 0, moved);
    dragIndex.value = index;
}

function onDrop() {
    dragIndex.value = null;
    const others = [...props.songs.start_pool, ...props.songs.tiebreaker].map(
        (song) => song.id,
    );

    return props.run(() =>
        api(reorder(props.gameId), {
            ids: [...ordered.value.map((song) => song.id), ...others],
        }),
    );
}
</script>

<template>
    <div class="flex flex-col gap-4 p-6">
        <div
            v-if="!editable"
            class="flex items-center gap-2 rounded-card bg-navy/5 px-4 py-3 text-sm text-navy/70"
        >
            <Lock class="size-4" />
            Das Spiel läuft – Songs können nicht mehr geändert werden. Fotos
            lassen sich weiterhin hinzufügen oder ersetzen.
        </div>

        <div
            class="flex flex-wrap items-center gap-x-6 border-b border-navy/15"
        >
            <button
                v-for="l in lists"
                :key="l.value"
                type="button"
                class="-mb-px border-b-2 py-2.5 text-sm"
                :class="
                    list === l.value
                        ? 'border-royal font-semibold text-royal'
                        : 'border-transparent text-navy/70'
                "
                @click="list = l.value"
            >
                {{ l.label }} ({{ songs[l.value].length }})
            </button>
            <label
                v-if="editable"
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
        <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="text-xs text-navy/70">{{ current.hint }}</p>
            <label
                v-if="editable"
                class="flex items-center gap-2 text-xs text-navy/70"
            >
                <input v-model="replaceOnImport" type="checkbox" />
                Beim Import bestehende Songs ersetzen
            </label>
        </div>

        <div
            class="flex flex-col overflow-hidden rounded-card border border-navy/15"
        >
            <div
                class="grid grid-cols-[32px_32px_44px_minmax(0,1fr)_minmax(0,1fr)_64px_32px] gap-2 bg-navy/70 px-3 py-2 text-xs text-white"
            >
                <span /><span>#</span><span>Foto</span><span>Titel</span>
                <span>Interpret</span><span>Jahr</span><span />
            </div>
            <div
                v-for="(song, index) in ordered"
                :key="song.id"
                :draggable="canDrag"
                class="grid h-11 grid-cols-[32px_32px_44px_minmax(0,1fr)_minmax(0,1fr)_64px_32px] items-center gap-2 border-t border-navy/15 px-3 text-sm"
                :class="[
                    index % 2 ? 'bg-royal/5' : 'bg-white',
                    dragIndex === index ? 'opacity-50' : '',
                ]"
                @dragstart="dragIndex = index"
                @dragover.prevent="canDrag && onDragOver(index)"
                @drop.prevent="canDrag && onDrop()"
                @dragend="dragIndex = null"
            >
                <GripVertical
                    class="size-4"
                    :class="
                        canDrag
                            ? 'cursor-grab text-navy/40'
                            : 'text-transparent'
                    "
                />
                <span class="text-navy/70">{{ index + 1 }}</span>
                <span class="group relative size-9">
                    <label
                        class="flex size-9 cursor-pointer items-center justify-center overflow-hidden rounded-md border border-navy/15 bg-white text-navy/40 hover:border-royal hover:text-royal"
                        :title="
                            song.image ? 'Foto ersetzen' : 'Foto hinzufügen'
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
                            @change="uploadImage(song, $event)"
                        />
                    </label>
                    <button
                        v-if="song.image"
                        type="button"
                        class="absolute -top-1.5 -right-1.5 hidden size-4 items-center justify-center rounded-full bg-alert text-white group-hover:flex"
                        title="Foto entfernen"
                        @click="
                            run(() => api(destroySongImage([gameId, song.id])))
                        "
                    >
                        <X class="size-3" />
                    </button>
                </span>
                <span class="truncate">{{ song.title }}</span>
                <span class="truncate text-navy/70">{{ song.artist }}</span>
                <span class="font-semibold">{{ song.year }}</span>
                <button
                    v-if="editable"
                    type="button"
                    class="text-navy/40 hover:text-alert"
                    :aria-label="`${song.title} löschen`"
                    @click="run(() => api(destroy([gameId, song.id])))"
                >
                    <Trash2 class="size-4" />
                </button>
                <span v-else />
            </div>
            <div
                v-if="!ordered.length"
                class="border-t border-navy/15 px-3 py-4 text-sm text-navy/70"
            >
                Noch keine Songs in dieser Liste.
            </div>
            <form
                v-if="editable"
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
        <p class="text-xs text-navy/70">
            CSV-Format: titel;interpret;jahr;rolle (rolle: runde, start,
            tiebreaker) · Maßgeblich ist das Original-Erscheinungsjahr.
        </p>
    </div>
</template>
