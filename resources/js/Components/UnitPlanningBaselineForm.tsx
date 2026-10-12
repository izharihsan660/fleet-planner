import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type UnitPlanningBaselineFormProps = {
    unitId: number;
    unitPlanningId: number;
    onCancel?: () => void;
    onSuccess?: () => void;
};

type UnitPlanningBaselineFormData = {
    last_done_date: string;
    last_done_km: string;
    next_due_date: string;
};

const dateRequirementMessage = 'Isi Kapan Terakhir Diganti atau Due Date.';

export default function UnitPlanningBaselineForm({ unitId, unitPlanningId, onCancel, onSuccess }: UnitPlanningBaselineFormProps) {
    const form = useForm<UnitPlanningBaselineFormData>({
        last_done_date: '',
        last_done_km: '',
        next_due_date: '',
    });
    const hasLastDoneDate = form.data.last_done_date !== '';
    const hasNextDueDate = form.data.next_due_date !== '';
    const hint = hasLastDoneDate && hasNextDueDate
        ? 'Due Date manual akan dipakai (tidak dihitung dari interval)'
        : hasLastDoneDate
            ? 'Due dihitung otomatis dari interval'
            : hasNextDueDate
                ? 'Tanggal terakhir diganti akan diestimasi sistem'
                : null;

    const updateDate = (field: 'last_done_date' | 'next_due_date', value: string) => {
        const otherField = field === 'last_done_date' ? 'next_due_date' : 'last_done_date';

        form.setData(field, value);
        form.clearErrors(field);

        if (value !== '' && form.errors[otherField] === dateRequirementMessage) {
            form.clearErrors(otherField);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (! hasLastDoneDate && ! hasNextDueDate) {
            form.setError({
                last_done_date: dateRequirementMessage,
                next_due_date: dateRequirementMessage,
            });

            return;
        }

        form.patch(route('units.plannings.baseline.update', [unitId, unitPlanningId]), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 grid gap-4 border-t border-border pt-4 lg:grid-cols-3">
            <div className="space-y-2">
                <Label htmlFor={`last-done-date-${unitPlanningId}`}>Kapan Terakhir Diganti (Tanggal)</Label>
                <Input
                    id={`last-done-date-${unitPlanningId}`}
                    type="date"
                    value={form.data.last_done_date}
                    onChange={(event) => updateDate('last_done_date', event.target.value)}
                    aria-invalid={Boolean(form.errors.last_done_date)}
                />
                <InputError message={form.errors.last_done_date} />
            </div>
            <div className="space-y-2">
                <Label htmlFor={`last-done-km-${unitPlanningId}`}>KM Saat Diganti (opsional)</Label>
                <Input
                    id={`last-done-km-${unitPlanningId}`}
                    type="number"
                    min="0"
                    value={form.data.last_done_km}
                    onChange={(event) => {
                        form.setData('last_done_km', event.target.value);
                        form.clearErrors('last_done_km');
                    }}
                    aria-invalid={Boolean(form.errors.last_done_km)}
                />
                <InputError message={form.errors.last_done_km} />
            </div>
            <div className="space-y-2">
                <Label htmlFor={`next-due-date-${unitPlanningId}`}>Due Date</Label>
                <Input
                    id={`next-due-date-${unitPlanningId}`}
                    type="date"
                    value={form.data.next_due_date}
                    onChange={(event) => updateDate('next_due_date', event.target.value)}
                    aria-invalid={Boolean(form.errors.next_due_date)}
                />
                <InputError message={form.errors.next_due_date} />
            </div>
            {hint && <p className="text-xs text-muted-foreground lg:col-span-3" aria-live="polite">{hint}</p>}
            <div className="flex flex-wrap gap-2 lg:col-span-3">
                <PrimaryButton disabled={form.processing}>Simpan Baseline</PrimaryButton>
                {onCancel && <SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton>}
            </div>
        </form>
    );
}
