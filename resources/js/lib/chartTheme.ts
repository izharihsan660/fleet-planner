import { AppliedTheme } from '@/Contexts/ThemeContext';

export const chartTheme = (theme: AppliedTheme) => ({
    axis: theme === 'dark' ? '#d4d4d8' : '#52525b',
    grid: theme === 'dark' ? '#3f3f46' : '#e4e4e7',
    tooltipBackground: theme === 'dark' ? '#18181b' : '#ffffff',
    tooltipBorder: theme === 'dark' ? '#3f3f46' : '#e4e4e7',
    tooltipText: theme === 'dark' ? '#f4f4f5' : '#18181b',
});
