import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type UnitPlanningBaselineFormProps = {
    unitId: number;
    unitPlanningId: number;
    onCancel?: () => void;
    onSuccess?: () => void;
};

export default function UnitPlanningBaselineForm({ unitId, unitPlanningId, onCancel, onSuccess }: UnitPlanningBaselineFormProps) {
    const form = useForm({ last_done_km: '', last_done_date: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('units.plannings.baseline.update', [unitId, unitPlanningId]), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-2">
            <div>
                <label className="mb-1 block text-sm font-medium text-foreground">KM Terakhir</label>
                <TextInput type="number" min="0" value={form.data.last_done_km} onChange={(event) => form.setData('last_done_km', event.target.value)} className="w-full" />
                <InputError className="mt-2" message={form.errors.last_done_km} />
            </div>
            <div>
                <label className="mb-1 block text-sm font-medium text-foreground">Tanggal Terakhir</label>
                <TextInput type="date" max={new Date().toISOString().slice(0, 10)} value={form.data.last_done_date} onChange={(event) => form.setData('last_done_date', event.target.value)} className="w-full" />
                <InputError className="mt-2" message={form.errors.last_done_date} />
            </div>
            <div className="flex flex-wrap gap-2 sm:col-span-2">
                <PrimaryButton disabled={form.processing}>Simpan Baseline</PrimaryButton>
                {onCancel && <SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton>}
            </div>
        </form>
    );
}
