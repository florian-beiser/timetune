<script setup lang="ts">
import { RotateCcw, TriangleAlert } from '@lucide/vue';
import { ref, watch } from 'vue';
import { update } from '@/actions/App/Http/Controllers/Host/GameActionController';
import BrandingEditor from '@/components/game/BrandingEditor.vue';
import JoinUrlEditor from '@/components/game/JoinUrlEditor.vue';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import type { HostProps } from '@/types/game';

const props = defineProps<{
    host: HostProps;
    busy: boolean;
    run: (action: () => Promise<unknown>) => Promise<void>;
}>();

const emit = defineEmits<{ reset: []; changed: [] }>();

const DEFAULT_TITLE = 'Konzert-Hitster';
const gameName = ref(props.host.game.name);
const title = ref(props.host.branding.title);
const countdownSeconds = ref(props.host.options.countdown_seconds ?? 30);

watch(
    () => [props.host.game.name, props.host.branding.title],
    () => {
        gameName.value = props.host.game.name;
        title.value = props.host.branding.title;
    },
);

/** Saves only the given fields; the server merges the options. */
function save(payload: { name?: string; options?: Record<string, unknown> }) {
    return props.run(() => api(update(props.host.game.id), payload));
}

function saveTitle() {
    const value = title.value.trim();

    return save({
        options: {
            title: value === '' || value === DEFAULT_TITLE ? null : value,
        },
    });
}
</script>

<template>
    <div class="grid gap-6 p-6 lg:grid-cols-2">
        <!-- Game -->
        <section
            class="flex flex-col gap-4 rounded-card border border-navy/15 p-5"
        >
            <h2 class="text-sm font-semibold">Spiel</h2>

            <label class="flex flex-col gap-1.5">
                <span class="text-xs text-navy/70">
                    Name des Spiels (Untertitel, Dashboard)
                </span>
                <input
                    v-model="gameName"
                    class="h-10 rounded-btn border border-navy/15 px-3 text-sm outline-none focus:border-royal"
                    @change="gameName.trim() && save({ name: gameName })"
                />
            </label>

            <form class="flex flex-col gap-1.5" @submit.prevent="saveTitle">
                <label for="game-title" class="text-xs text-navy/70">
                    Titel (groß auf Leinwand und Handys)
                </label>
                <div class="flex gap-2">
                    <input
                        id="game-title"
                        v-model="title"
                        maxlength="40"
                        :placeholder="DEFAULT_TITLE"
                        class="h-10 min-w-0 flex-1 rounded-btn border border-navy/15 px-3 text-sm outline-none focus:border-royal"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        class="h-10"
                        :disabled="busy || title.trim() === host.branding.title"
                    >
                        Übernehmen
                    </Button>
                </div>
                <span class="text-xs text-navy/70">
                    Leer = „{{ DEFAULT_TITLE }}“. Höchstens 40 Zeichen.
                </span>
            </form>

            <div class="flex flex-col gap-4 border-t border-navy/10 pt-4">
                <label class="flex items-center gap-3">
                    <span class="flex-1">
                        <span class="block text-sm">Bonusfrage pro Runde</span>
                        <span class="block text-xs text-navy/70">
                            Multiple Choice „Wer ist der Interpret?“, +1
                            Bonuspunkt
                        </span>
                    </span>
                    <input
                        type="checkbox"
                        class="toggle"
                        :checked="host.options.bonus_question"
                        @change="
                            save({
                                options: {
                                    bonus_question: (
                                        $event.target as HTMLInputElement
                                    ).checked,
                                },
                            })
                        "
                    />
                </label>
                <label class="flex items-center gap-3">
                    <span class="flex-1">
                        <span class="block text-sm">Countdown</span>
                        <span class="block text-xs text-navy/70">
                            Schließt die Abgabe automatisch nach
                            <input
                                v-model.number="countdownSeconds"
                                type="number"
                                min="10"
                                max="600"
                                class="w-12 rounded border border-navy/15 px-1"
                                @change="
                                    host.options.countdown_seconds &&
                                    save({
                                        options: {
                                            countdown_seconds: countdownSeconds,
                                        },
                                    })
                                "
                            />
                            s
                        </span>
                    </span>
                    <input
                        type="checkbox"
                        class="toggle"
                        :checked="host.options.countdown_seconds !== null"
                        @change="
                            save({
                                options: {
                                    countdown_seconds: (
                                        $event.target as HTMLInputElement
                                    ).checked
                                        ? countdownSeconds
                                        : null,
                                },
                            })
                        "
                    />
                </label>
            </div>
        </section>

        <!-- Join address -->
        <section class="rounded-card border border-navy/15 p-5">
            <JoinUrlEditor
                :game-id="host.game.id"
                :code="host.game.code"
                :join-url="host.options.join_url"
                :default-join-url="host.default_join_url"
                :current-join-url="host.join_url"
                @changed="emit('changed')"
            />
        </section>

        <!-- Appearance -->
        <section
            class="flex flex-col gap-4 rounded-card border border-navy/15 p-5 lg:col-span-2"
        >
            <h2 class="text-sm font-semibold">
                Darstellung für Spieler und Leinwand
            </h2>
            <BrandingEditor
                :game-id="host.game.id"
                :branding="host.branding"
                @changed="emit('changed')"
            />
        </section>

        <!-- Danger zone -->
        <section
            class="flex flex-wrap items-center gap-4 rounded-card border border-alert/40 bg-alert/5 p-5 lg:col-span-2"
        >
            <TriangleAlert class="size-5 text-alert" />
            <div class="flex-1">
                <h2 class="text-sm font-semibold text-alert">Gefahrenzone</h2>
                <p class="text-xs text-navy/70">
                    Spiel zurücksetzen: Alle Runden, Abgaben und
                    Tiebreaker-Antworten werden gelöscht; Spieler und
                    Startkarten bleiben. Das Spiel beginnt wieder in der Lobby.
                </p>
            </div>
            <Button
                variant="outline"
                class="border-alert/40 text-alert hover:text-alert"
                :disabled="busy"
                @click="emit('reset')"
            >
                <RotateCcw />Spiel zurücksetzen
            </Button>
        </section>
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
