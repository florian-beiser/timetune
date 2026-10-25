<script setup lang="ts">
import { Link2, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { update } from '@/actions/App/Http/Controllers/Host/GameActionController';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';

const props = defineProps<{
    gameId: number;
    code: string;
    /** Stored value (normalized by the server) or null for the default. */
    joinUrl: string | null;
    defaultJoinUrl: string;
    /** Effective link behind the QR code right now. */
    currentJoinUrl: string;
}>();

const emit = defineEmits<{ changed: [] }>();

const input = ref(props.joinUrl ?? '');
const busy = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);

watch(
    () => props.joinUrl,
    (value) => (input.value = value ?? ''),
);

/**
 * Same rules as App\Services\JoinUrl, for the live preview only:
 * bare host -> append /play/CODE, path -> as is, {code} -> filled in.
 */
const preview = computed(() => {
    let url = input.value.trim();

    if (url === '') {
        return props.defaultJoinUrl;
    }

    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(url)) {
        const host = url.replace(/[:/?].*$/, '').toLowerCase();
        const isLocal =
            host === 'localhost' ||
            /^\d{1,3}(\.\d{1,3}){3}$/.test(host) ||
            host.endsWith('.local');
        url = `${isLocal ? 'http' : 'https'}://${url}`;
    }

    if (url.includes('{code}')) {
        return url.replaceAll('{code}', props.code);
    }

    try {
        const path = new URL(url).pathname;

        return path === '' || path === '/'
            ? `${url.replace(/\/+$/, '')}/play/${props.code}`
            : url;
    } catch {
        return null;
    }
});

const isDirty = computed(() => input.value.trim() !== (props.joinUrl ?? ''));

async function save(value: string | null) {
    busy.value = true;
    error.value = null;
    saved.value = false;

    try {
        await api(update(props.gameId), { options: { join_url: value } });
        saved.value = true;
        emit('changed');
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <h2 class="text-sm font-semibold">Beitritts-Adresse (QR-Code)</h2>
        <form
            class="flex flex-col gap-2"
            @submit.prevent="save(input.trim() === '' ? null : input)"
        >
            <label
                class="flex h-10 items-center gap-2 rounded-btn border border-navy/15 px-3 focus-within:border-royal"
            >
                <Link2 class="size-4 shrink-0 text-navy/40" />
                <span class="sr-only">Basis-Adresse oder Kurzlink</span>
                <input
                    v-model="input"
                    :placeholder="defaultJoinUrl.replace(/\/play\/.*$/, '')"
                    class="w-full text-sm outline-none"
                    autocomplete="off"
                    spellcheck="false"
                />
            </label>
            <p class="text-xs text-navy/70">
                Leer = Adresse der App. Nur Host (z. B.
                <code>hitster.musikverein.at</code>) →
                <code>/play/Code</code> wird angehängt. Mit Pfad (z. B.
                <code>bit.ly/mv-hitster</code>) → unverändert.
                <code>{code}</code> wird durch den Spielcode ersetzt.
            </p>
            <p class="text-xs break-all">
                <span class="mr-1 text-navy/70">QR-Code führt zu:</span>
                <span v-if="preview" class="font-semibold">{{ preview }}</span>
                <span v-else class="text-alert">keine gültige Adresse</span>
            </p>
            <p v-if="error" class="text-sm text-alert">{{ error }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="busy || !isDirty || !preview"
                >
                    Übernehmen
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="busy || joinUrl === null"
                    @click="save(null)"
                >
                    <RotateCcw />Standard
                </Button>
                <span v-if="saved" class="text-xs text-success">
                    Gespeichert – die Leinwand zeigt
                    {{ currentJoinUrl.replace(/^https?:\/\//, '') }}.
                </span>
            </div>
        </form>
    </section>
</template>
