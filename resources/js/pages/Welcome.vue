<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Music } from '@lucide/vue';
import { find } from '@/actions/App/Http/Controllers/PlayController';
import { dashboard, login } from '@/routes';
</script>

<template>
    <Head title="Mitspielen" />

    <div class="min-h-dvh bg-night text-white">
        <div class="mx-auto flex min-h-dvh max-w-md flex-col px-6 pt-14 pb-8">
            <div class="flex items-center gap-2 text-xs text-white/50">
                <Music class="size-3.5" />TimeTune
            </div>
            <h1 class="mt-2 text-[32px] leading-tight font-semibold">
                Konzert-Hitster
            </h1>
            <p class="mt-2 text-base text-pretty text-white/75">
                Scanne den QR-Code auf der Leinwand oder gib den Spielcode ein.
            </p>

            <Form
                v-bind="find.form()"
                class="mt-10 flex flex-col gap-2"
                #default="{ errors, processing }"
            >
                <label for="code" class="text-xs text-white/75"
                    >Spielcode</label
                >
                <input
                    id="code"
                    name="code"
                    maxlength="6"
                    autocapitalize="characters"
                    autocomplete="off"
                    required
                    placeholder="K7M2QX"
                    class="h-13 rounded-btn border border-royal bg-white/6 px-4 text-xl font-semibold tracking-[.2em] uppercase outline-none placeholder:text-white/30 focus:ring-3 focus:ring-royal/35"
                />
                <p v-if="errors.code" class="text-sm text-[#ff8a95]">
                    {{ errors.code }}
                </p>
                <button
                    type="submit"
                    :disabled="processing"
                    class="mt-4 h-13 rounded-btn bg-royal text-base font-semibold transition-colors hover:bg-royal-hover disabled:opacity-50"
                >
                    Weiter
                </button>
            </Form>

            <div class="mt-auto pt-10 text-center text-xs text-white/50">
                <Link
                    :href="$page.props.auth.user ? dashboard() : login()"
                    class="underline-offset-4 hover:underline"
                >
                    Für Organisatoren: Spiele verwalten
                </Link>
            </div>
        </div>
    </div>
</template>
