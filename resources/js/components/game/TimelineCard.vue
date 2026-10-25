<script setup lang="ts">
import type { Card } from '@/types/game';

withDefaults(
    defineProps<{
        card: Card;
        variant?: 'default' | 'new' | 'compact';
    }>(),
    { variant: 'default' },
);
</script>

<template>
    <div
        v-if="variant === 'compact'"
        class="flex items-baseline gap-3.5 rounded-btn bg-white/6 px-3 py-2"
    >
        <span class="min-w-11 text-base font-semibold">{{ card.year }}</span>
        <span class="text-xs text-white/75">
            {{ card.title }} · {{ card.artist }}
        </span>
    </div>
    <div
        v-else
        class="flex items-center gap-3.5 rounded-card border px-3.5 py-2.5"
        :class="
            variant === 'new'
                ? 'border-success bg-success/15'
                : 'border-white/15 bg-white/6'
        "
    >
        <span class="min-w-16 text-2xl font-semibold">{{ card.year }}</span>
        <span class="flex min-w-0 flex-col text-xs leading-snug">
            <span class="truncate text-white">{{ card.title }}</span>
            <span
                class="truncate"
                :class="variant === 'new' ? 'text-white/75' : 'text-white/50'"
            >
                {{ card.artist }}
            </span>
        </span>
        <span
            v-if="variant === 'new'"
            class="ml-auto text-xs font-semibold text-success-light"
        >
            Neu
        </span>
    </div>
</template>
