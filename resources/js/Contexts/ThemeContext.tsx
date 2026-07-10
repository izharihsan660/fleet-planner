import { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { createContext, PropsWithChildren, useCallback, useContext, useEffect, useMemo, useState } from 'react';

export type ThemePreference = 'light' | 'dark' | 'system';
export type AppliedTheme = 'light' | 'dark';

type ThemeContextValue = {
    preference: ThemePreference;
    appliedTheme: AppliedTheme;
    setPreference: (preference: ThemePreference) => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);
const storageKey = 'fleet-planner-theme';

const getSystemTheme = (): AppliedTheme => {
    if (typeof window === 'undefined') {
        return 'light';
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

const applyTheme = (theme: AppliedTheme): void => {
    document.documentElement.classList.toggle('dark', theme === 'dark');
    document.documentElement.style.colorScheme = theme;
};

export function ThemeProvider({ children }: PropsWithChildren) {
    const page = usePage<PageProps>();
    const sharedPreference = page.props.theme?.preference ?? page.props.auth.user?.theme_preference ?? 'system';
    const [preference, setLocalPreference] = useState<ThemePreference>(sharedPreference);
    const [systemTheme, setSystemTheme] = useState<AppliedTheme>(getSystemTheme);

    useEffect(() => {
        setLocalPreference(sharedPreference);
        window.localStorage.setItem(storageKey, sharedPreference);
    }, [sharedPreference]);

    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const updateSystemTheme = () => setSystemTheme(media.matches ? 'dark' : 'light');

        updateSystemTheme();
        media.addEventListener('change', updateSystemTheme);

        return () => media.removeEventListener('change', updateSystemTheme);
    }, []);

    const appliedTheme = preference === 'system' ? systemTheme : preference;

    useEffect(() => {
        applyTheme(appliedTheme);
    }, [appliedTheme]);

    const setPreference = useCallback((nextPreference: ThemePreference) => {
        setLocalPreference(nextPreference);
        window.localStorage.setItem(storageKey, nextPreference);

        router.patch(route('profile.theme.update'), {
            theme_preference: nextPreference,
        }, {
            preserveScroll: true,
            preserveState: true,
        });
    }, []);

    const value = useMemo(() => ({ preference, appliedTheme, setPreference }), [appliedTheme, preference, setPreference]);

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
    const context = useContext(ThemeContext);

    if (!context) {
        throw new Error('useTheme must be used within ThemeProvider');
    }

    return context;
}
