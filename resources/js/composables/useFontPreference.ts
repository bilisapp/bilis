import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { FontPreference } from '@/types';

export type { FontPreference };

export type FontPreferenceOption = {
    value: FontPreference;
    label: string;
};

export const FONT_PREFERENCE_OPTIONS: FontPreferenceOption[] = [
    { value: 'geist', label: 'Geist' },
    { value: 'ibm-plex-mono', label: 'IBM Plex Mono' },
];

export type UseFontPreferenceReturn = {
    font: Ref<FontPreference>;
    fontLabel: ComputedRef<string>;
    updateFontPreference: (value: FontPreference) => void;
};

export function updateFontOnDocument(value: FontPreference): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (value === 'ibm-plex-mono') {
        document.documentElement.setAttribute('data-font', 'ibm-plex-mono');
    } else {
        document.documentElement.removeAttribute('data-font');
    }
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredFont = (): FontPreference | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return localStorage.getItem('font') as FontPreference | null;
};

export function initializeFontPreference(): void {
    if (typeof window === 'undefined') {
        return;
    }

    updateFontOnDocument(getStoredFont() || 'geist');
}

const font = ref<FontPreference>('geist');

export function useFontPreference(): UseFontPreferenceReturn {
    onMounted(() => {
        const savedFont = getStoredFont();

        if (savedFont) {
            font.value = savedFont;
        }
    });

    const fontLabel = computed(
        () =>
            FONT_PREFERENCE_OPTIONS.find(
                (option) => option.value === font.value,
            )?.label ?? 'Geist',
    );

    function updateFontPreference(value: FontPreference) {
        font.value = value;

        localStorage.setItem('font', value);
        setCookie('font', value);
        updateFontOnDocument(value);
    }

    return {
        font,
        fontLabel,
        updateFontPreference,
    };
}
