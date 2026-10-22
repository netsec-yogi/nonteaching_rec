import { ref, watch } from 'vue';

/**
 * Colour mode, as in the Hostel Management app: light by default, toggled from the top bar,
 * remembered per browser. `system` follows the operating system. public/theme-init.js applies
 * the same preference before the first paint.
 */
export type ThemePref = 'light' | 'dark' | 'system';
const KEY = 'ntrec.theme';

function read(): ThemePref {
    try {
        const v = localStorage.getItem(KEY);
        return v === 'dark' || v === 'system' ? v : 'light';
    } catch {
        return 'light';
    }
}

export const themePref = ref<ThemePref>(read());
export const isDark = ref(false);

const media = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function apply(): void {
    isDark.value = themePref.value === 'dark' || (themePref.value === 'system' && !!media?.matches);
    document.documentElement.setAttribute('data-bs-theme', isDark.value ? 'dark' : 'light');
}

if (typeof document !== 'undefined') {
    apply();
    watch(themePref, (v) => {
        try { localStorage.setItem(KEY, v); } catch { /* storage unavailable */ }
        apply();
    });
    media?.addEventListener('change', apply);
}

export function toggleTheme(): void {
    themePref.value = isDark.value ? 'light' : 'dark';
}
