import { echo, useConnectionStatus } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import type { Ref } from 'vue';

type Handlers = Record<string, (payload: any) => void>;

/**
 * Subscribes to the game's realtime channels and falls back to polling
 * every 3 s while the WebSocket is not connected.
 */
export function useLive(options: {
    gameId: Ref<number | null>;
    playerId?: Ref<number | null>;
    hostChannel?: boolean;
    handlers: Handlers;
    playerHandlers?: Handlers;
    hostHandlers?: Handlers;
    poll?: () => void;
}) {
    const status = useConnectionStatus();
    const isLive = computed(() => status.value === 'connected');
    const subscribed: string[] = [];
    let pollTimer: number | undefined;

    function listen(
        name: string,
        type: 'public' | 'private',
        handlers: Handlers | undefined,
    ) {
        if (!handlers) {
            return;
        }

        const channel =
            type === 'public' ? echo().channel(name) : echo().private(name);

        for (const [event, handler] of Object.entries(handlers)) {
            channel.listen(`.${event}`, handler);
        }

        subscribed.push(name);
    }

    function unsubscribe() {
        while (subscribed.length) {
            echo().leave(subscribed.pop()!);
        }
    }

    function subscribe() {
        unsubscribe();

        const gameId = options.gameId.value;

        if (!gameId) {
            return;
        }

        listen(`game.${gameId}`, 'public', options.handlers);

        if (options.hostChannel) {
            listen(`game.${gameId}.host`, 'private', options.hostHandlers);
        }

        const playerId = options.playerId?.value;

        if (playerId) {
            listen(`player.${playerId}`, 'private', options.playerHandlers);
        }
    }

    function updatePolling() {
        window.clearInterval(pollTimer);
        pollTimer = undefined;

        if (!isLive.value && options.poll && options.gameId.value) {
            pollTimer = window.setInterval(options.poll, 3000);
        }
    }

    onMounted(() => {
        subscribe();
        updatePolling();
    });

    watch(() => [options.gameId.value, options.playerId?.value], subscribe);

    // After a reconnect, missed events are caught up with one poll.
    watch(isLive, (live, wasLive) => {
        updatePolling();

        if (live && wasLive === false && options.gameId.value) {
            options.poll?.();
        }
    });

    onBeforeUnmount(() => {
        window.clearInterval(pollTimer);
        unsubscribe();
    });

    return { status, isLive };
}
