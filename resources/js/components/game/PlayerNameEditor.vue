<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { rename } from '@/actions/App/Http/Controllers/Api/PlayerController';
import { api } from '@/lib/api';

const props = defineProps<{ name: string }>();

const emit = defineEmits<{ renamed: [name: string] }>();

const editing = ref(false);
const draft = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);

async function start() {
    draft.value = props.name;
    error.value = null;
    editing.value = true;
    await nextTick();
    input.value?.select();
}

async function save() {
    if (draft.value.trim() === props.name) {
        editing.value = false;

        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const response = await api<{ name: string }>(rename(), {
            name: draft.value,
        });
        emit('renamed', response.name);
        editing.value = false;
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form
        v-if="editing"
        class="flex flex-col gap-1.5 px-5 pb-6"
        @submit.prevent="save"
    >
        <div class="flex gap-2">
            <input
                ref="input"
                v-model="draft"
                maxlength="20"
                autocomplete="nickname"
                enterkeyhint="done"
                aria-label="Neuer Name"
                class="h-11 min-w-0 flex-1 rounded-btn border border-royal bg-white/6 px-3 text-base text-white outline-none"
                @keydown.esc="editing = false"
            />
            <button
                type="submit"
                :disabled="busy || draft.trim() === ''"
                class="h-11 rounded-btn bg-royal px-4 text-sm font-semibold text-white disabled:opacity-50"
            >
                Speichern
            </button>
        </div>
        <div class="flex items-center justify-between text-xs">
            <span v-if="error" class="text-[#ff8a95]">{{ error }}</span>
            <span v-else class="text-white/50">
                Gleiche Namen werden nummeriert.
            </span>
            <button
                type="button"
                class="text-white/50 underline-offset-2 hover:underline"
                @click="editing = false"
            >
                Abbrechen
            </button>
        </div>
    </form>

    <div v-else class="px-5 pb-6 text-center text-xs text-white/50">
        Du spielst als
        <span class="font-semibold text-white/75">{{ name }}</span>
        ·
        <button
            type="button"
            class="underline underline-offset-2 hover:text-white"
            @click="start"
        >
            Name ändern
        </button>
    </div>
</template>
