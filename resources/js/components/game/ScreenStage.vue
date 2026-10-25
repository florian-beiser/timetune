<script setup lang="ts">
import { useWindowSize } from '@vueuse/core';
import { computed } from 'vue';

/**
 * Fixed 1280×720 stage scaled to the beamer, like the design frames.
 */
const { width, height } = useWindowSize();
const scale = computed(() => Math.min(width.value / 1280, height.value / 720));
</script>

<template>
    <div class="fixed inset-0 overflow-hidden bg-night">
        <div
            class="absolute top-1/2 left-1/2 h-[720px] w-[1280px] overflow-hidden text-white bg-beamer"
            :style="{
                transform: `translate(-50%, -50%) scale(${scale})`,
            }"
        >
            <slot />
        </div>
    </div>
</template>
