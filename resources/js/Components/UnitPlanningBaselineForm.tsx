import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type UnitPlanningBaselineFormProps = {
    unitId: number;
    unitPlanningId: number;
    onCancel?: () => void;
    onSuccess?: () => void;
};

type Mode = 'known' | 'estimate';

export default function UnitPlanningBaselineForm({ unitId, unitPlanningId, onCancel, onSuccess }: UnitPlanningBaselineFormProps) {
    const [mode, setMode] = useState<Mode>('known');
    const form = useForm({ last_done_km: '', last_done_date: '', next_due_date: '', is_estimated: false });

    const pickMode = (next: Mode) => {
        setMode(next);
        form.setData((data) => ({
            ...data,
            last_done_date: next === 'known' ? data.last_done_date : '',
            next_due_date: next === 'estimate' ? data.next_due_date : '',
            is_estimated: next === 'estimate',
        }));
        form.clearErrors();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('units.plannings.baseline.update', [unitId, unitPlanningId]), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setMode('known');
                onSuccess?.();
            },
        });
    };

    const tab = (value: Mode, label: string) => (
        <button
            type="button"
            onClick={() => pickMode(value)}
            aria-pressed={mode === value}
            className={`rounded-md border px-3 py-1.5 text-sm ${mode === value ? 'border-primary bg-primary/10 font-medium text-primary' : 'border-border text-muted-foreground'}`}
        >
            {label}
        </button>
    );

    return (
        <form onSubmit={submit} className="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-2">
            <div className="flex flex-wrap gap-2 sm:col-span-2">
                {tab('known', 'Tahu data aslinya')}
                {tab('estimate', 'Perkiraan saja')}
            </div>

            <div>
                <label className="mb-1 block text-sm font-medium text-foreground">
                    {mode === 'estimate' ? 'Perkiraan KM terakhir diganti' : 'KM Terakhir'}
                </label>
                <TextInput type="number" min="1" value={form.data.last_done_km} onChange={(event) => form.setData('last_done_km', event.target.value)} className="w-full" />
                <InputError className="mt-2" message={form.errors.last_done_km} />
            </div>

            {mode === 'known' ? (
                <div>
                    <label className="mb-1 block text-sm font-medium text-foreground">Tanggal Terakhir</label>
                    <TextInput type="date" max={new Date().toISOString().slice(0, 10)} value={form.data.last_done_date} onChange={(event) => form.setData('last_done_date', event.target.value)} className="w-full" />
                    <InputError className="mt-2" message={form.errors.last_done_date} />
                </div>
            ) : (
                <div>
                    <label className="mb-1 block text-sm font-medium text-foreground">Perkiraan jatuh tempo</label>
                    <TextInput type="date" value={form.data.next_due_date} onChange={(event) => form.setData('next_due_date', event.target.value)} className="w-full" />
                    <InputError className="mt-2" message={form.errors.next_due_date} />
                </div>
            )}

            {mode === 'estimate' && (
                <p className="text-sm text-amber-700 sm:col-span-2 dark:text-amber-200">
                    Disimpan sebagai perkiraan dan diberi tanda Estimated, supaya bisa dibedakan dari data asli dan ditimpa saat data lapangan masuk.
                </p>
            )}

            <div className="flex flex-wrap gap-2 sm:col-span-2">
                <PrimaryButton disabled={form.processing}>{mode === 'estimate' ? 'Simpan Perkiraan' : 'Simpan Baseline'}</PrimaryButton>
                {onCancel && <SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton>}
            </div>
        </form>
    );
}
