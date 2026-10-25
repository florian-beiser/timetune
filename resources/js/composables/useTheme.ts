import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { Theme } from '@/types/game';

/**
 * CSS variables for a custom colour theme. Without a theme nothing is
 * overridden, so the views look exactly like the default design.
 */
export function themeStyle(theme: Theme | null): Record<string, string> {
    if (!theme) {
        return {};
    }

    const { accent, background } = theme;

    return {
        '--color-royal': accent,
        '--color-royal-hover': `color-mix(in srgb, ${accent} 82%, black)`,
        '--color-night': background,
        '--beamer-1': `color-mix(in srgb, ${accent} 55%, ${background})`,
        '--beamer-2': `color-mix(in srgb, ${accent} 25%, ${background})`,
        backgroundColor: background,
    };
}

export function useTheme(
    theme: () => Theme | null,
): ComputedRef<Record<string, string>> {
    return computed(() => themeStyle(theme()));
}

/** WCAG contrast ratio of a hex colour against white text. */
export function contrastWithWhite(hex: string): number {
    const channel = (offset: number) => {
        const value = parseInt(hex.slice(offset, offset + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    };
    const luminance =
        0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);

    return 1.05 / (luminance + 0.05);
}

export const DEFAULT_THEME: Theme = {
    accent: '#1967e6',
    background: '#04143f',
};
