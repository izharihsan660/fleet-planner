import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { BackdateThresholds, PageProps, User } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

export const DEFAULT_BACKDATE_THRESHOLDS: BackdateThresholds = {
    self_service_days: 30,
    max_days: 90,
    extended_note_min_length: 30,
};

const daysBackdated = (completedDate: string): number => {
    if (!completedDate) {
        return 0;
    }

    const today = new Date();
    const chosen = new Date(`${completedDate}T00:00:00`);
    const diff = Math.floor((Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()) - Date.UTC(chosen.getFullYear(), chosen.getMonth(), chosen.getDate())) / 86400000);

    return Number.isNaN(diff) ? 0 : Math.max(0, diff);
};

/**
 * Penanda mundur untuk form Complete. Ambangnya dikirim backend
 * (system_thresholds), jadi tingkat penandanya ikut berubah tanpa deploy.
 */
export function BackdateNotice({ completedDate, thresholds, className }: { completedDate: string; thresholds: BackdateThresholds; className?: string }) {
    const days = daysBackdated(completedDate);

    if (days <= 0) {
        return null;
    }

    if (days > thresholds.max_days) {
        return <div role="alert" className={`rounded-lg border-2 border-red-400 bg-red-50 p-3 text-sm font-semibold text-red-800 dark:border-red-500/50 dark:bg-red-500/15 dark:text-red-200 ${className ?? ''}`}>Mundur {days} hari — melewati batas {thresholds.max_days} hari. Minta Superadmin mencatatnya lewat Koreksi Tanggal Selesai.</div>;
    }

    if (days > thresholds.self_service_days) {
        return <div role="alert" className={`rounded-lg border-2 border-orange-400 bg-orange-50 p-3 text-sm font-semibold text-orange-900 dark:border-orange-500/50 dark:bg-orange-500/15 dark:text-orange-200 ${className ?? ''}`}>Mundur {days} hari — masih dalam batas maksimal {thresholds.max_days} hari. Catatan penyelesaian opsional.</div>;
    }

    return <div role="status" className={`rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-200 ${className ?? ''}`}>Mundur {days} hari — catatan penyelesaian opsional.</div>;
}

type AssignmentFormData = {
    assigned_mechanic_id: string;
    scheduled_date: string;
};

type MechanicOption = Pick<User, 'id' | 'name' | 'site_id'>;

type ItemFormBase = {
    workOrderId: number;
    itemId: number;
    itemName: string;
    onCancel?: () => void;
    onSuccess?: () => void;
};

export function AssignmentFields({ mechanics, assignedMechanicId, scheduledDate, setAssignedMechanicId, setScheduledDate, mechanicError, scheduledDateError }: { mechanics: MechanicOption[]; assignedMechanicId: string; scheduledDate: string; setAssignedMechanicId: (value: string) => void; setScheduledDate: (value: string) => void; mechanicError?: string; scheduledDateError?: string }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col">
                <label className="mb-1 block text-xs font-medium text-muted-foreground">Mekanik Penanggung Jawab</label>
                <p className="mb-1 text-xs text-muted-foreground">Berlaku untuk seluruh item unit ini.</p>
                <div className="mt-auto">
                    <Select value={assignedMechanicId || 'none'} onValueChange={(value) => setAssignedMechanicId(value === 'none' ? '' : value)}>
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Belum ditentukan" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Belum ditentukan</SelectItem>
                            {mechanics.map((mechanic) => <SelectItem key={mechanic.id} value={String(mechanic.id)}>{mechanic.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <InputError message={mechanicError} />
                </div>
            </div>
            <div className="flex flex-col">
                <label className="mb-1 block text-xs font-medium text-muted-foreground">Jadwal Mekanik — setelah approval</label>
                <p className="mb-1 text-xs text-muted-foreground">Khusus item ini. Masuk daftar tugas mekanik pada tanggal ini.</p>
                <div className="mt-auto">
                    <TextInput type="date" value={scheduledDate} onChange={(event) => setScheduledDate(event.target.value)} className="w-full" />
                    <InputError message={scheduledDateError} />
                </div>
            </div>
        </div>
    );
}

export function ReasonForm({ title, onCancel, onSubmit, processing, reason, setReason, error }: { title: string; onCancel?: () => void; onSubmit: (event: FormEvent) => void; processing: boolean; reason: string; setReason: (reason: string) => void; error?: string }) {
    return <form onSubmit={onSubmit} className="mt-4 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/15"><h4 className="font-semibold text-amber-900 dark:text-amber-100">{title}</h4><Textarea className="text-sm" rows={3} value={reason} placeholder="Alasan" onChange={(event) => setReason(event.target.value)} /><InputError message={error} /><div className="flex gap-2"><PrimaryButton disabled={processing}>Simpan</PrimaryButton><SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton></div></form>;
}

export function ReplaceForm({ workOrderId, itemId, itemName, onCancel, onSuccess, defaultReason, plannedDate, mechanics, assignedMechanicId, scheduledDate, showPlannedDate = true }: ItemFormBase & { defaultReason: string | null; plannedDate?: string | null; mechanics: MechanicOption[]; assignedMechanicId: number | null; scheduledDate: string | null; showPlannedDate?: boolean }) {
    const form = useForm<AssignmentFormData & { reason: string; planned_date?: string }>({
        reason: defaultReason ?? '',
        assigned_mechanic_id: assignedMechanicId?.toString() ?? '',
        scheduled_date: scheduledDate ?? '',
        ...(showPlannedDate ? { planned_date: plannedDate ?? '' } : {}),
    });
    const [showConfirm, setShowConfirm] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => form.post(route('work-orders.items.replace', [workOrderId, itemId]), { preserveScroll: true, onSuccess: () => { onSuccess?.(); onCancel?.(); }, onFinish: () => setShowConfirm(false) });

    return <><form onSubmit={submit} className="mt-4 space-y-3 rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-500/40 dark:bg-sky-500/15"><h4 className="font-semibold text-sky-900 dark:text-sky-100">Ajukan Penggantian</h4><Textarea className="text-sm" rows={3} value={form.data.reason} placeholder="Catatan / alasan opsional" onChange={(event) => form.setData('reason', event.target.value)} /><InputError message={form.errors.reason} />{showPlannedDate && <div><label className="mb-1 block text-xs font-medium text-muted-foreground">Rencana Penggantian — sebelum approval</label><p className="mb-1 text-xs text-muted-foreground">Catatan rencana planner. Tidak menjadwalkan mekanik.</p><TextInput className="w-full" type="date" value={form.data.planned_date ?? ''} onChange={(event) => form.setData('planned_date', event.target.value)} /><InputError className="mt-1" message={form.errors.planned_date} /></div>}<AssignmentFields mechanics={mechanics} assignedMechanicId={form.data.assigned_mechanic_id} scheduledDate={form.data.scheduled_date} setAssignedMechanicId={(value) => form.setData('assigned_mechanic_id', value)} setScheduledDate={(value) => form.setData('scheduled_date', value)} mechanicError={form.errors.assigned_mechanic_id} scheduledDateError={form.errors.scheduled_date} /><div className="flex gap-2"><PrimaryButton disabled={form.processing}>Ajukan Penggantian</PrimaryButton><SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton></div></form><ConfirmDialog show={showConfirm} message={`Ajukan Penggantian untuk ${itemName}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}

export function PostponeForm({ workOrderId, itemId, itemName, onCancel, onSuccess, defaultReason, defaultDueKm, defaultDueDate }: ItemFormBase & { defaultReason: string | null; defaultDueKm: number | null; defaultDueDate: string | null }) {
    const form = useForm({ reason: defaultReason ?? '', new_due_km: defaultDueKm?.toString() ?? '', new_due_date: defaultDueDate?.slice(0, 10) ?? '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => form.post(route('work-orders.items.postpone', [workOrderId, itemId]), { preserveScroll: true, onSuccess: () => { onSuccess?.(); onCancel?.(); }, onFinish: () => setShowConfirm(false) });

    return <><form onSubmit={submit} className="mt-4 grid gap-3 rounded-xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-500/40 dark:bg-orange-500/15 md:grid-cols-3"><div className="md:col-span-3"><h4 className="font-semibold text-orange-900 dark:text-orange-100">Ajukan Penundaan</h4><Textarea className="mt-2 text-sm" rows={3} value={form.data.reason} placeholder="Alasan penundaan" onChange={(event) => form.setData('reason', event.target.value)} /><InputError message={form.errors.reason} /></div><div><TextInput className="w-full" type="number" value={form.data.new_due_km} onChange={(event) => form.setData('new_due_km', event.target.value)} /><InputError className="mt-1" message={form.errors.new_due_km} /></div><div><TextInput className="w-full" type="date" value={form.data.new_due_date} onChange={(event) => form.setData('new_due_date', event.target.value)} /><InputError className="mt-1" message={form.errors.new_due_date} /></div><div className="flex gap-2"><PrimaryButton disabled={form.processing}>Ajukan Penundaan</PrimaryButton><SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton></div></form><ConfirmDialog show={showConfirm} message={`Ajukan Penundaan untuk ${itemName} ke KM ${form.data.new_due_km || '-'} dan tanggal ${form.data.new_due_date || '-'}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}

export function BlockedItemForm({ itemId, itemName, onCancel, onSuccess }: Omit<ItemFormBase, 'workOrderId'>) {
    const form = useForm({ reason: '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => form.post(route('work-order-items.blocked', itemId), { preserveScroll: true, onSuccess: () => { onSuccess?.(); onCancel?.(); }, onFinish: () => setShowConfirm(false) });

    return <><ReasonForm title="Tandai Menunggu Part" reason={form.data.reason} setReason={(reason) => form.setData('reason', reason)} error={form.errors.reason} processing={form.processing} onCancel={onCancel} onSubmit={submit} /><ConfirmDialog show={showConfirm} message={`Tandai ${itemName} sebagai menunggu part?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}

export function CompleteItemForm({ workOrderId, itemId, itemName, currentOdo, onCancel, onSuccess, stacked = false }: ItemFormBase & { currentOdo: number; stacked?: boolean }) {
    // Ambang dibaca dari props halaman supaya tidak perlu dioper lewat setiap
    // kartu Kanban; halaman yang belum mengirimnya jatuh ke default backend.
    const backdateThresholds = usePage<PageProps<{ backdateThresholds?: BackdateThresholds }>>().props.backdateThresholds ?? DEFAULT_BACKDATE_THRESHOLDS;
    const { data, setData, post, processing, errors } = useForm({ completed_odo: currentOdo.toString(), completed_date: new Date().toISOString().slice(0, 10), notes: '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => post(route('work-orders.items.complete', [workOrderId, itemId]), { preserveScroll: true, onSuccess: () => onSuccess?.(), onFinish: () => setShowConfirm(false) });

    return <><form onSubmit={submit} className={stacked ? 'mt-3 space-y-2 rounded-xl border bg-muted/40 p-3' : 'mt-3 grid gap-3 rounded-md bg-gray-50 p-3 md:grid-cols-4'}><BackdateNotice completedDate={data.completed_date} thresholds={backdateThresholds} className={stacked ? undefined : 'md:col-span-4'} /><div><label className="mb-1 block text-xs font-medium text-muted-foreground">KM saat selesai</label><TextInput className="w-full" type="number" value={data.completed_odo} onChange={(event) => setData('completed_odo', event.target.value)} /><InputError message={errors.completed_odo} className="mt-1" /></div><div><label className="mb-1 block text-xs font-medium text-muted-foreground">Tanggal selesai</label><TextInput className="w-full" type="date" value={data.completed_date} onChange={(event) => setData('completed_date', event.target.value)} /><InputError message={errors.completed_date} className="mt-1" /></div><div><TextInput className="w-full" value={data.notes} placeholder="Catatan" onChange={(event) => setData('notes', event.target.value)} /><InputError message={errors.notes} className="mt-1" /></div><div className="flex gap-2"><PrimaryButton disabled={processing}>Selesai</PrimaryButton>{stacked && <SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton>}</div></form><ConfirmDialog show={showConfirm} message={`Selesaikan ${itemName} di KM ${data.completed_odo || '-'}?`} processing={processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}
