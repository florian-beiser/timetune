<script setup lang="ts">
withDefaults(defineProps<{ large?: boolean }>(), { large: false });
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full bg-alert font-semibold tracking-wide text-white uppercase"
        :class="large ? 'h-9 px-4 text-base' : 'h-6 px-2.5 text-xs'"
    >
        <span
            class="animate-pulse rounded-full bg-white"
            :class="large ? 'size-2.5' : 'size-1.5'"
        />
        Live
    </span>
</template>
