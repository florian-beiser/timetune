<script setup lang="ts">
const props = defineProps<{ rank: number }>();

const medal: Record<number, string> = {
    1: 'bg-gold text-navy',
    2: 'bg-silver text-navy',
    3: 'bg-bronze text-navy',
};
</script>

<template>
    <span
        class="flex size-8 shrink-0 items-center justify-center rounded-full text-base font-semibold"
        :class="medal[props.rank] ?? 'text-white/75'"
    >
        {{ props.rank }}
    </span>
</template>
