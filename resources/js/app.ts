import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { defineAsyncComponent } from 'vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { configureEcho } from '@laravel/echo-vue';

// Loaded on demand so the player page does not ship the sidebar & co.
const AppLayout = defineAsyncComponent(() => import('@/layouts/AppLayout.vue'));
const AuthLayout = defineAsyncComponent(
    () => import('@/layouts/AuthLayout.vue'),
);
const SettingsLayout = defineAsyncComponent(
    () => import('@/layouts/settings/Layout.vue'),
);

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name.startsWith('play/'):
            case name.startsWith('screen/'):
            case name.startsWith('host/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#1967e6',
    },
});

// The WebSocket address comes from the server at runtime (see app.blade.php),
// so one build works for every host. Without a configured host, the phone
// connects to the host and port it loaded the page from (reverse proxy).
const reverbMeta = (name: string): string | undefined =>
    document
        .querySelector(`meta[name="reverb-${name}"]`)
        ?.getAttribute('content') || undefined;
const pageIsHttps = window.location.protocol === 'https:';
const reverbScheme = reverbMeta('scheme') ?? (pageIsHttps ? 'https' : 'http');
const reverbPort = Number(
    reverbMeta('port') ?? (window.location.port || (pageIsHttps ? 443 : 80)),
);

configureEcho({
    broadcaster: 'reverb',
    key: reverbMeta('key') ?? import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: reverbMeta('host') ?? window.location.hostname,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
