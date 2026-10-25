<script setup lang="ts">
import { ImageUp, RotateCcw, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    destroyLogo,
    storeLogo,
} from '@/actions/App/Http/Controllers/Host/BrandingController';
import { update } from '@/actions/App/Http/Controllers/Host/GameActionController';
import { Button } from '@/components/ui/button';
import {
    contrastWithWhite,
    DEFAULT_THEME,
    themeStyle,
} from '@/composables/useTheme';
import { api } from '@/lib/api';
import type { Branding, Theme } from '@/types/game';

const props = defineProps<{
    gameId: number;
    branding: Branding;
}>();

const emit = defineEmits<{ changed: [] }>();

const draft = ref<Theme>({ ...(props.branding.theme ?? DEFAULT_THEME) });
const busy = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);
const logoInput = ref<HTMLInputElement | null>(null);

watch(
    () => props.branding.theme,
    (theme) => (draft.value = { ...(theme ?? DEFAULT_THEME) }),
);

const isDefault = computed(
    () =>
        draft.value.accent.toLowerCase() === DEFAULT_THEME.accent &&
        draft.value.background.toLowerCase() === DEFAULT_THEME.background,
);
const isDirty = computed(() => {
    const current = props.branding.theme ?? DEFAULT_THEME;

    return (
        current.accent.toLowerCase() !== draft.value.accent.toLowerCase() ||
        current.background.toLowerCase() !==
            draft.value.background.toLowerCase()
    );
});

const warnings = computed(() => {
    const list: string[] = [];

    if (contrastWithWhite(draft.value.accent) < 3) {
        list.push('Die Akzentfarbe ist zu hell für weiße Schrift auf Buttons.');
    }

    if (contrastWithWhite(draft.value.background) < 7) {
        list.push(
            'Der Hintergrund ist recht hell – im dunklen Saal blendet das, und weiße Schrift wird schlecht lesbar.',
        );
    }

    return list;
});

const preview = computed(() =>
    themeStyle(isDefault.value ? null : draft.value),
);

async function run(action: () => Promise<unknown>) {
    busy.value = true;
    error.value = null;
    saved.value = false;

    try {
        await action();
        emit('changed');

        return true;
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);

        return false;
    } finally {
        busy.value = false;
    }
}

async function saveTheme(theme: Theme | null) {
    if (await run(() => api(update(props.gameId), { options: { theme } }))) {
        saved.value = true;
    }
}

function uploadLogo(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    const data = new FormData();
    data.append('file', file);

    void run(async () => {
        await api(storeLogo(props.gameId), data);

        if (logoInput.value) {
            logoInput.value.value = '';
        }
    });
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <h2 class="text-sm font-semibold">
            Darstellung für Spieler und Leinwand
        </h2>

        <div class="grid gap-5 sm:grid-cols-[minmax(0,1fr)_200px]">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <span class="text-xs text-navy/70">Logo</span>
                    <div class="flex flex-wrap items-center gap-3">
                        <div
                            class="flex h-14 w-40 items-center justify-center rounded-card border border-dashed border-navy/15 bg-night p-2"
                        >
                            <img
                                v-if="branding.logo_url"
                                :src="branding.logo_url"
                                alt="Logo"
                                class="max-h-full max-w-full object-contain"
                            />
                            <span v-else class="text-xs text-white/50">
                                kein Logo
                            </span>
                        </div>
                        <label
                            class="flex cursor-pointer items-center gap-1.5 rounded-btn border border-navy/15 px-3 py-1.5 text-xs font-semibold hover:bg-navy/5"
                        >
                            <ImageUp class="size-4" />
                            {{ branding.logo_url ? 'Ersetzen' : 'Hochladen' }}
                            <input
                                ref="logoInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                class="hidden"
                                @change="uploadLogo"
                            />
                        </label>
                        <button
                            v-if="branding.logo_url"
                            type="button"
                            class="text-xs text-alert hover:underline"
                            @click="run(() => api(destroyLogo(gameId)))"
                        >
                            Entfernen
                        </button>
                    </div>
                    <span class="text-xs text-navy/70">
                        PNG mit transparentem Hintergrund, helle Variante für
                        dunklen Grund. JPG, PNG oder WebP, max. 4 MB.
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-xs text-navy/70">Akzentfarbe</span>
                        <span
                            class="flex h-10 items-center gap-2 rounded-btn border border-navy/15 px-2"
                        >
                            <input
                                v-model="draft.accent"
                                type="color"
                                class="size-7 cursor-pointer rounded border-0 bg-transparent p-0"
                            />
                            <span class="font-mono text-xs">
                                {{ draft.accent }}
                            </span>
                        </span>
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-xs text-navy/70">Hintergrund</span>
                        <span
                            class="flex h-10 items-center gap-2 rounded-btn border border-navy/15 px-2"
                        >
                            <input
                                v-model="draft.background"
                                type="color"
                                class="size-7 cursor-pointer rounded border-0 bg-transparent p-0"
                            />
                            <span class="font-mono text-xs">
                                {{ draft.background }}
                            </span>
                        </span>
                    </label>
                </div>

                <div
                    v-for="warning in warnings"
                    :key="warning"
                    class="flex items-start gap-2 rounded-card bg-amber-soft px-3 py-2 text-xs text-amber-dark"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{ warning }}
                </div>
                <p v-if="error" class="text-sm text-alert">{{ error }}</p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        :disabled="busy || !isDirty"
                        @click="saveTheme(isDefault ? null : draft)"
                    >
                        Farben übernehmen
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="
                            busy || (branding.theme === null && isDefault)
                        "
                        @click="saveTheme(null)"
                    >
                        <RotateCcw />Standard
                    </Button>
                    <span v-if="saved" class="text-xs text-success">
                        Gespeichert – Handys übernehmen es beim nächsten Laden.
                    </span>
                </div>
            </div>

            <!-- Live preview with the draft colours -->
            <div class="flex flex-col gap-2" :style="preview">
                <span class="text-xs text-navy/70">Vorschau</span>
                <div
                    class="flex flex-col gap-2 rounded-[18px] bg-night p-3 text-white shadow-dialog"
                >
                    <img
                        v-if="branding.logo_url"
                        :src="branding.logo_url"
                        alt=""
                        class="max-h-6 max-w-28 object-contain"
                    />
                    <span v-else class="text-xs font-semibold">
                        Konzert-Hitster
                    </span>
                    <div
                        class="mx-auto flex h-12 w-10 items-center justify-center rounded-md bg-royal text-xl font-semibold"
                    >
                        ?
                    </div>
                    <div
                        class="flex items-center gap-2 rounded-md border border-white/15 bg-white/6 px-2 py-1.5"
                    >
                        <span class="text-sm font-semibold">1982</span>
                        <span class="text-[10px] text-white/50">Africa</span>
                    </div>
                    <div
                        class="flex h-8 items-center justify-center rounded-md bg-royal text-xs font-semibold shadow-[0_0_0_2px_rgba(255,255,255,0.15)]"
                    >
                        Deine Wahl
                    </div>
                </div>
                <div
                    class="flex aspect-video items-center justify-center rounded-md text-xs font-semibold text-white bg-beamer"
                >
                    Leinwand
                </div>
            </div>
        </div>
    </section>
</template>
