<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Monitor, Settings2, Users } from '@lucide/vue';
import { store } from '@/actions/App/Http/Controllers/GameController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import type { GameStateName } from '@/types/game';

defineProps<{
    games: {
        id: number;
        code: string;
        name: string;
        state: GameStateName;
        players_count: number;
        created_at: string | null;
        host_url: string;
        screen_url: string;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Spiele', href: dashboard() }],
    },
});

const stateLabels: Record<GameStateName, string> = {
    LOBBY: 'Lobby',
    ROUND_OPEN: 'Runde offen',
    ROUND_CLOSED: 'Geschlossen',
    REVEALED: 'Aufgelöst',
    TIEBREAKER: 'Tiebreaker',
    FINISHED: 'Beendet',
};
</script>

<template>
    <Head title="Spiele" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Form
            v-bind="store.form()"
            class="flex max-w-xl flex-col gap-2"
            #default="{ errors, processing }"
        >
            <label for="name" class="text-sm font-semibold">
                Neues Spiel anlegen
            </label>
            <div class="flex gap-2">
                <Input
                    id="name"
                    name="name"
                    required
                    placeholder="z. B. Herbstkonzert 2026"
                />
                <Button type="submit" :disabled="processing">Anlegen</Button>
            </div>
            <InputError :message="errors.name" />
        </Form>

        <div class="flex flex-col overflow-hidden rounded-card border">
            <div
                v-for="game in games"
                :key="game.id"
                class="flex flex-wrap items-center gap-4 border-b px-4 py-3 last:border-0"
            >
                <div class="flex min-w-0 flex-col">
                    <span class="font-semibold">{{ game.name }}</span>
                    <span class="text-xs text-muted-foreground">
                        Code {{ game.code }} · {{ stateLabels[game.state] }}
                    </span>
                </div>
                <span
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <Users class="size-4" />{{ game.players_count }}
                </span>
                <div class="ml-auto flex gap-2">
                    <Button
                        as="a"
                        :href="game.screen_url"
                        target="_blank"
                        variant="outline"
                        size="sm"
                    >
                        <Monitor />Leinwand
                    </Button>
                    <Button as="a" :href="game.host_url" size="sm">
                        <Settings2 />Moderation
                    </Button>
                </div>
            </div>
            <div
                v-if="!games.length"
                class="px-4 py-6 text-sm text-muted-foreground"
            >
                Noch keine Spiele angelegt.
            </div>
        </div>
    </div>
</template>
