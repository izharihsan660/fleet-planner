import InputError from '@/Components/InputError';
import { Button } from '@/Components/ui/button';
import { ThemePreference, useTheme } from '@/Contexts/ThemeContext';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';

const options: Array<{ value: ThemePreference; label: string; description: string }> = [
    { value: 'light', label: 'Light', description: 'Tampilan terang tetap.' },
    { value: 'dark', label: 'Dark', description: 'Tampilan gelap tetap.' },
    { value: 'system', label: 'System', description: 'Ikut OS/browser.' },
];

export default function UpdateThemePreferenceForm({ className = '' }: { className?: string }) {
    const { preference, setPreference } = useTheme();
    const errors = usePage().props.errors as { theme_preference?: string };

    return (
        <section className={className}>
            <header>
                <h2 className="text-base font-semibold text-foreground">Theme Preference</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Pilih tampilan aplikasi. Preferensi tersimpan di akun dan berlaku di semua device.
                </p>
            </header>

            <div className="mt-6 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Theme preference">
                {options.map((option) => {
                    const active = preference === option.value;

                    return (
                        <Button
                            key={option.value}
                            type="button"
                            variant={active ? 'default' : 'outline'}
                            className={cn(
                                'h-auto flex-col items-start gap-1 rounded-xl p-4 text-left',
                                !active && 'bg-card hover:bg-muted',
                            )}
                            role="radio"
                            aria-checked={active}
                            onClick={() => setPreference(option.value)}
                        >
                            <span className="font-semibold">{option.label}</span>
                            <span className={cn('text-xs', active ? 'text-primary-foreground/80' : 'text-muted-foreground')}>
                                {option.description}
                            </span>
                        </Button>
                    );
                })}
            </div>

            <InputError className="mt-2" message={errors.theme_preference} />
        </section>
    );
}
