<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Lock, QrCode, Timer, Trophy, Users } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import LiveChip from '@/components/game/LiveChip.vue';
import RankBadge from '@/components/game/RankBadge.vue';
import ScreenStage from '@/components/game/ScreenStage.vue';
import { useLive } from '@/composables/useLive';
import { useTheme } from '@/composables/useTheme';
import type { LeaderboardRow, ScreenProps } from '@/types/game';

const props = defineProps<ScreenProps>();

const themeVars = useTheme(() => props.branding.theme);

// Long custom titles get smaller so they fit next to the QR code.
const titleClass = computed(() => {
    const length = props.branding.title.length;

    return length <= 16
        ? 'text-8xl'
        : length <= 26
          ? 'text-7xl'
          : 'text-[56px]';
});

const submitted = ref(props.submitted);
const totalPlayers = ref(props.total_players);

watch(
    () => [props.submitted, props.total_players],
    () => {
        submitted.value = props.submitted;
        totalPlayers.value = props.total_players;
    },
);

function reload() {
    router.reload();
}

useLive({
    gameId: computed(() => props.game.id),
    handlers: {
        StateChanged: reload,
        LeaderboardUpdated: reload,
        SubmissionsCount: (payload: {
            submitted: number;
            total_players: number;
        }) => {
            submitted.value = payload.submitted;
            totalPlayers.value = payload.total_players;
        },
    },
    poll: reload,
});

const state = computed(() => props.state.state);
const roundLabel = computed(
    () => `Stück ${props.state.round_number} von ${props.state.total_rounds}`,
);
const submittedPercent = computed(() =>
    totalPlayers.value
        ? Math.round((submitted.value / totalPlayers.value) * 100)
        : 0,
);

const now = ref(Date.now());
let clock: number | undefined;
onMounted(
    () => (clock = window.setInterval(() => (now.value = Date.now()), 500)),
);
onBeforeUnmount(() => window.clearInterval(clock));

const secondsLeft = computed(() => {
    if (!props.state.closes_at || state.value !== 'ROUND_OPEN') {
        return null;
    }

    return Math.max(
        0,
        Math.ceil(
            (new Date(props.state.closes_at).getTime() - now.value) / 1000,
        ),
    );
});

const podium = computed(() => {
    const [first, second, third] = props.podium;

    return { first, second, third };
});
const rest = computed<LeaderboardRow[]>(() => props.top10.slice(3));
</script>

<template>
    <Head :title="`Leinwand · ${game.name}`" />

    <ScreenStage :style="themeVars">
        <!-- 2a Lobby -->
        <div
            v-if="state === 'LOBBY'"
            class="grid h-full grid-cols-[1fr_420px] gap-16 px-20 py-18"
        >
            <div class="flex flex-col justify-between">
                <div class="flex flex-col gap-4">
                    <img
                        v-if="branding.logo_url"
                        :src="branding.logo_url"
                        :alt="game.name"
                        class="mb-2 max-h-20 max-w-80 self-start object-contain"
                    />
                    <div class="text-xl text-white/75">{{ game.name }}</div>
                    <div
                        class="leading-none font-semibold text-balance break-words"
                        :class="titleClass"
                    >
                        {{ branding.title }}
                    </div>
                    <div
                        class="max-w-140 text-[26px] leading-snug text-pretty text-white/75"
                    >
                        Wir spielen live. Du ordnest jedes Stück nach seinem
                        Erscheinungsjahr in deine Zeitleiste ein.
                    </div>
                </div>
                <div class="flex gap-10 text-xl">
                    <div
                        v-for="(step, index) in [
                            'QR-Code scannen',
                            'Namen eingeben',
                            'Mitspielen',
                        ]"
                        :key="step"
                        class="flex items-center gap-3 whitespace-nowrap"
                    >
                        <span
                            class="flex size-10 items-center justify-center rounded-full bg-royal font-semibold"
                        >
                            {{ index + 1 }}
                        </span>
                        {{ step }}
                    </div>
                </div>
            </div>
            <div class="flex flex-col gap-5">
                <div
                    class="flex flex-col items-center gap-4 rounded-podium bg-white p-7 text-navy"
                >
                    <div
                        v-if="qr_svg"
                        class="size-75 [&>svg]:size-full"
                        v-html="qr_svg"
                    />
                    <div
                        v-else
                        class="flex size-75 items-center justify-center text-navy/70"
                    >
                        <QrCode class="mr-2 size-5" />QR-Code
                    </div>
                    <div
                        class="text-center text-[22px] leading-tight font-semibold break-all"
                    >
                        {{ join_url_short }}
                    </div>
                </div>
                <div class="flex items-center justify-between px-2">
                    <div>
                        <div class="text-sm text-white/50">Spielcode</div>
                        <div class="text-[32px] font-semibold tracking-[.08em]">
                            {{ game.code }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-white/50">Dabei</div>
                        <div
                            class="flex items-center gap-2 text-[32px] font-semibold"
                        >
                            <Users class="size-6 text-white/50" />
                            {{ totalPlayers }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2b Round open / closed -->
        <div
            v-else-if="state === 'ROUND_OPEN' || state === 'ROUND_CLOSED'"
            class="flex h-full flex-col px-20 py-16"
        >
            <div class="flex items-center justify-between">
                <LiveChip v-if="state === 'ROUND_OPEN'" large />
                <span
                    v-else
                    class="flex h-9 items-center gap-2 rounded-full bg-white/15 px-4 text-base font-semibold"
                >
                    <Lock class="size-4" />Geschlossen
                </span>
                <span class="text-xl text-white/50">{{ join_url_short }}</span>
            </div>
            <div class="flex flex-1 flex-col justify-center gap-5">
                <div class="text-[32px] text-white/75">{{ roundLabel }}</div>
                <div class="text-[120px] leading-none font-semibold">
                    {{
                        state === 'ROUND_OPEN'
                            ? 'Jetzt einordnen!'
                            : 'Abgabe geschlossen'
                    }}
                </div>
                <div
                    v-if="secondsLeft !== null"
                    class="flex items-center gap-2 text-[32px] text-amber"
                >
                    <Timer class="size-8" />
                    Noch {{ secondsLeft }} Sekunden
                </div>
                <div
                    v-else-if="state === 'ROUND_CLOSED'"
                    class="text-[32px] text-white/75"
                >
                    Gleich wird aufgelöst …
                </div>
            </div>
            <div class="flex flex-col gap-3.5">
                <div class="flex justify-between text-[26px]">
                    <span>Abgaben</span>
                    <span class="font-semibold">
                        {{ submitted }} / {{ totalPlayers }}
                    </span>
                </div>
                <div class="h-5 overflow-hidden rounded-full bg-white/15">
                    <div
                        class="h-full rounded-full bg-royal transition-[width] duration-500"
                        :style="{ width: `${submittedPercent}%` }"
                    />
                </div>
            </div>
        </div>

        <!-- 2c Reveal -->
        <div
            v-else-if="state === 'REVEALED'"
            class="grid h-full grid-cols-[minmax(0,1fr)_440px] gap-16 px-20 py-16"
        >
            <div v-if="reveal" class="flex flex-col justify-center gap-4">
                <div class="text-[26px] text-white/75">{{ roundLabel }}</div>
                <div class="flex items-center gap-8">
                    <div
                        class="leading-[.9] font-semibold"
                        :class="
                            reveal.song.image ? 'text-[160px]' : 'text-[240px]'
                        "
                    >
                        {{ reveal.song.year }}
                    </div>
                    <img
                        v-if="reveal.song.image"
                        :src="reveal.song.image.lg"
                        alt=""
                        class="size-48 shrink-0 rounded-podium object-cover shadow-ranking"
                    />
                </div>
                <div class="text-5xl leading-tight font-semibold">
                    {{ reveal.song.title }}
                </div>
                <div class="text-[32px] text-white/75">
                    {{ reveal.song.artist }}
                </div>
                <div class="mt-6 flex items-center gap-5">
                    <div
                        class="h-4 w-60 overflow-hidden rounded-full bg-white/15"
                    >
                        <div
                            class="h-full bg-success"
                            :style="{ width: `${reveal.percent_correct}%` }"
                        />
                    </div>
                    <span class="text-[32px] font-semibold">
                        {{ reveal.percent_correct }} % lagen richtig
                    </span>
                </div>
            </div>
            <div v-else class="flex flex-col justify-center gap-4">
                <div class="text-[26px] text-white/75">{{ roundLabel }}</div>
                <div class="text-8xl font-semibold">Stück übersprungen</div>
            </div>
            <div class="flex flex-col justify-center gap-1.5">
                <div class="mb-2 text-xl text-white/50">Rangliste · Top 10</div>
                <div
                    v-for="row in top10"
                    :key="row.player_id"
                    class="flex h-11.5 items-center gap-4 rounded-card bg-white/6 px-4"
                >
                    <RankBadge :rank="row.rank" />
                    <span class="truncate text-2xl">{{ row.name }}</span>
                    <span class="ml-auto text-2xl font-semibold">
                        {{ row.score }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Tiebreaker -->
        <div
            v-else-if="state === 'TIEBREAKER'"
            class="flex h-full flex-col justify-center gap-6 px-20 py-16"
        >
            <div class="text-[32px] text-white/75">Tiebreaker</div>
            <div class="text-8xl leading-none font-semibold">
                Aus welchem Jahr ist dieser Song?
            </div>
            <div class="text-5xl font-semibold">
                {{ props.state.tiebreaker?.title }}
                <span class="font-normal text-white/75">
                    · {{ props.state.tiebreaker?.artist }}
                </span>
            </div>
            <div class="text-[26px] text-white/75">
                Jahr auf dem Handy eingeben. Die kleinste Abweichung gewinnt,
                bei Gleichstand die frühere Abgabe.
            </div>
        </div>

        <!-- 2d Finished -->
        <div v-else class="flex h-full flex-col gap-8 px-20 py-12">
            <div class="flex items-baseline justify-between">
                <span class="flex items-center gap-6 text-5xl font-semibold">
                    <img
                        v-if="branding.logo_url"
                        :src="branding.logo_url"
                        :alt="game.name"
                        class="max-h-14 max-w-60 self-center object-contain"
                    />
                    Die Sieger
                </span>
                <span class="text-xl text-white/50">
                    {{ game.name }} · {{ totalPlayers }} Spieler ·
                    {{ props.state.total_rounds }} Stücke
                </span>
            </div>
            <div class="grid grid-cols-[1fr_1.15fr_1fr] items-end gap-6">
                <div
                    v-if="podium.second"
                    class="flex h-55 flex-col justify-between rounded-podium p-6 shadow-ranking bg-grad-silver"
                >
                    <span class="text-5xl leading-none font-semibold">
                        {{ podium.second.rank }}.
                    </span>
                    <div>
                        <div class="truncate text-[32px] font-semibold">
                            {{ podium.second.name }}
                        </div>
                        <div class="text-xl">
                            {{ podium.second.score }} Karten
                        </div>
                    </div>
                </div>
                <div v-else />
                <div
                    v-if="podium.first"
                    class="flex h-67.5 flex-col justify-between rounded-podium p-6 text-navy shadow-ranking bg-grad-gold"
                >
                    <span
                        class="flex items-center gap-3 text-[64px] leading-none font-semibold"
                    >
                        <Trophy class="size-10" /> 1.
                    </span>
                    <div>
                        <div
                            class="truncate text-5xl leading-tight font-semibold"
                        >
                            {{ podium.first.name }}
                        </div>
                        <div class="text-xl">
                            {{ podium.first.score }} Karten
                        </div>
                    </div>
                </div>
                <div v-else />
                <div
                    v-if="podium.third"
                    class="flex h-47.5 flex-col justify-between rounded-podium p-6 shadow-ranking bg-grad-bronze"
                >
                    <span class="text-5xl leading-none font-semibold">
                        {{ podium.third.rank }}.
                    </span>
                    <div>
                        <div class="truncate text-[32px] font-semibold">
                            {{ podium.third.name }}
                        </div>
                        <div class="text-xl">
                            {{ podium.third.score }} Karten
                            <template
                                v-if="podium.third.tiebreak_deviation !== null"
                            >
                                · Tiebreaker
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-4 gap-x-6 gap-y-2">
                <div
                    v-for="row in rest"
                    :key="row.player_id"
                    class="flex h-11 items-center gap-3 rounded-card bg-white/6 px-3.5 text-xl"
                >
                    <span class="min-w-6 text-white/50">{{ row.rank }}</span>
                    <span class="truncate">{{ row.name }}</span>
                    <span class="ml-auto font-semibold">{{ row.score }}</span>
                </div>
            </div>
        </div>
    </ScreenStage>
</template>
