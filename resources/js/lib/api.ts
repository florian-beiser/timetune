export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * JSON request against a Wayfinder route. Cookies (session, player token)
 * are sent automatically; errors are thrown as ApiError with a German message.
 */
export async function api<T = unknown>(
    route: { url: string; method: string },
    body?: Record<string, unknown> | FormData,
    headers: Record<string, string> = {},
): Promise<T> {
    const isForm = body instanceof FormData;
    const response = await fetch(route.url, {
        method: route.method.toUpperCase(),
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
            ...headers,
        },
        body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const errors = (data.errors ?? {}) as Record<string, string[]>;
        const first = Object.values(errors)[0]?.[0];
        const fallback =
            response.status === 429
                ? 'Zu viele Anfragen – bitte kurz warten.'
                : 'Das hat nicht geklappt. Bitte versuche es noch einmal.';

        throw new ApiError(
            first ?? data.message ?? fallback,
            response.status,
            errors,
        );
    }

    return data as T;
}
