import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import StatusBadge from '@/Components/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PlanningItem, UnitPlanning, User, WorkOrder, WorkOrderItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ResourceItem<T> = { data: T };

type AssignmentFormData = {
    assigned_mechanic_id: string;
    scheduled_date: string;
};

type ActiveItemForm = {
    itemId: number;
    type: 'replace' | 'postpone' | 'blocked';
} | null;

function AssignmentFields({ mechanics, assignedMechanicId, scheduledDate, setAssignedMechanicId, setScheduledDate, mechanicError, scheduledDateError }: { mechanics: User[]; assignedMechanicId: string; scheduledDate: string; setAssignedMechanicId: (value: string) => void; setScheduledDate: (value: string) => void; mechanicError?: string; scheduledDateError?: string }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">Mekanik Opsional</label>
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
            <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">Tanggal Rencana Opsional</label>
                <TextInput type="date" value={scheduledDate} min={new Date().toISOString().slice(0, 10)} onChange={(event) => setScheduledDate(event.target.value)} className="w-full" />
                <InputError message={scheduledDateError} />
            </div>
        </div>
    );
}

function ReasonForm({ title, onCancel, onSubmit, processing, reason, setReason, error }: { title: string; onCancel: () => void; onSubmit: (event: FormEvent) => void; processing: boolean; reason: string; setReason: (reason: string) => void; error?: string }) {
    return <form onSubmit={onSubmit} className="mt-4 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4"><h4 className="font-semibold text-amber-900">{title}</h4><Textarea className="text-sm" rows={3} value={reason} placeholder="Alasan" onChange={(event) => setReason(event.target.value)} /><InputError message={error} /><div className="flex gap-2"><PrimaryButton disabled={processing}>Simpan</PrimaryButton><SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton></div></form>;
}


function ManualFindingForm({ unitId, planningItems, mechanics }: { unitId: number; planningItems: PlanningItem[]; mechanics: User[] }) {
    const form = useForm<{ planning_item_ids: number[]; reason: string } & AssignmentFormData>({ planning_item_ids: [], reason: '', assigned_mechanic_id: '', scheduled_date: '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const toggleItem = (itemId: number) => form.setData('planning_item_ids', form.data.planning_item_ids.includes(itemId) ? form.data.planning_item_ids.filter((id) => id !== itemId) : [...form.data.planning_item_ids, itemId]);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => form.post(route('units.manual-findings.store', unitId), { preserveScroll: true, onSuccess: () => form.reset(), onFinish: () => setShowConfirm(false) });

    return <><form onSubmit={submit} className="space-y-4 rounded-xl border border-violet-200 bg-violet-50 p-4"><div><h4 className="font-semibold text-violet-900">Lapor Temuan</h4><p className="mt-1 text-sm text-violet-700">Pilih item standar yang ditemukan rusak di lapangan meski belum due.</p></div><div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{planningItems.map((item) => <label key={item.id} className="flex items-center gap-2 rounded-lg border bg-background p-2 text-sm"><Checkbox checked={form.data.planning_item_ids.includes(item.id)} onCheckedChange={() => toggleItem(item.id)} /> <span>{item.name}</span></label>)}</div><InputError message={form.errors.planning_item_ids} /><Textarea className="text-sm" rows={3} value={form.data.reason} placeholder="Contoh: Brake Pad ditemukan sudah tipis, perlu diganti sekarang meski belum due." onChange={(event) => form.setData('reason', event.target.value)} /><InputError message={form.errors.reason} /><AssignmentFields mechanics={mechanics} assignedMechanicId={form.data.assigned_mechanic_id} scheduledDate={form.data.scheduled_date} setAssignedMechanicId={(value) => form.setData('assigned_mechanic_id', value)} setScheduledDate={(value) => form.setData('scheduled_date', value)} mechanicError={form.errors.assigned_mechanic_id} scheduledDateError={form.errors.scheduled_date} /><PrimaryButton disabled={form.processing || form.data.planning_item_ids.length === 0}>Submit Lapor Temuan</PrimaryButton></form><ConfirmDialog show={showConfirm} message={`Ajukan ${form.data.planning_item_ids.length} item temuan manual untuk approval SPV?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}

function ReplaceForm({ item, workOrderId, mechanics, onCancel }: { item: WorkOrderItem; workOrderId: number; mechanics: User[]; onCancel: () => void }) {
    const form = useForm<AssignmentFormData & { reason: string }>({ reason: '', assigned_mechanic_id: '', scheduled_date: '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => form.post(route('work-orders.items.replace', [workOrderId, item.id]), { preserveScroll: true, onSuccess: onCancel, onFinish: () => setShowConfirm(false) });

    return <><form onSubmit={submit} className="mt-4 space-y-3 rounded-xl border border-sky-200 bg-sky-50 p-4"><h4 className="font-semibold text-sky-900">Submit Replace</h4><Textarea className="text-sm" rows={3} value={form.data.reason} placeholder="Catatan / alasan opsional" onChange={(event) => form.setData('reason', event.target.value)} /><InputError message={form.errors.reason} /><AssignmentFields mechanics={mechanics} assignedMechanicId={form.data.assigned_mechanic_id} scheduledDate={form.data.scheduled_date} setAssignedMechanicId={(value) => form.setData('assigned_mechanic_id', value)} setScheduledDate={(value) => form.setData('scheduled_date', value)} mechanicError={form.errors.assigned_mechanic_id} scheduledDateError={form.errors.scheduled_date} /><div className="flex gap-2"><PrimaryButton disabled={form.processing}>Submit Replace</PrimaryButton><SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton></div></form><ConfirmDialog show={showConfirm} message={`Submit Replace untuk ${item.planning_item?.name ?? 'item maintenance'}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}

function PostponeForm({ item, workOrderId, onCancel }: { item: WorkOrderItem; workOrderId: number; onCancel: () => void }) {
    const form = useForm({ reason: '', new_due_km: item.unit_planning?.next_due_km?.toString() ?? '', new_due_date: item.unit_planning?.next_due_date?.slice(0, 10) ?? '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => form.post(route('work-orders.items.postpone', [workOrderId, item.id]), { preserveScroll: true, onSuccess: onCancel, onFinish: () => setShowConfirm(false) });

    return <><form onSubmit={submit} className="mt-4 grid gap-3 rounded-xl border border-orange-200 bg-orange-50 p-4 md:grid-cols-3"><div className="md:col-span-3"><h4 className="font-semibold text-orange-900">Submit Postpone</h4><Textarea className="mt-2 text-sm" rows={3} value={form.data.reason} placeholder="Alasan postpone" onChange={(event) => form.setData('reason', event.target.value)} /><InputError message={form.errors.reason} /></div><div><TextInput className="w-full" type="number" value={form.data.new_due_km} onChange={(event) => form.setData('new_due_km', event.target.value)} /><InputError className="mt-1" message={form.errors.new_due_km} /></div><div><TextInput className="w-full" type="date" value={form.data.new_due_date} onChange={(event) => form.setData('new_due_date', event.target.value)} /><InputError className="mt-1" message={form.errors.new_due_date} /></div><div className="flex gap-2"><PrimaryButton disabled={form.processing}>Submit Postpone</PrimaryButton><SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton></div></form><ConfirmDialog show={showConfirm} message={`Submit Postpone untuk ${item.planning_item?.name ?? 'item maintenance'} ke KM ${form.data.new_due_km || '-'} dan tanggal ${form.data.new_due_date || '-'}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}

function CompleteItemForm({ item, workOrderId, currentOdo }: { item: WorkOrderItem; workOrderId: number; currentOdo: number }) {
    const { data, setData, post, processing, errors } = useForm({ completed_odo: currentOdo.toString(), completed_date: new Date().toISOString().slice(0, 10), notes: '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => post(route('work-orders.items.complete', [workOrderId, item.id]), { preserveScroll: true, onFinish: () => setShowConfirm(false) });

    return <><form onSubmit={submit} className="mt-3 grid gap-3 rounded-md bg-gray-50 p-3 md:grid-cols-4"><div><TextInput className="w-full" type="number" value={data.completed_odo} onChange={(event) => setData('completed_odo', event.target.value)} /><InputError message={errors.completed_odo} className="mt-1" /></div><div><TextInput className="w-full" type="date" value={data.completed_date} onChange={(event) => setData('completed_date', event.target.value)} /><InputError message={errors.completed_date} className="mt-1" /></div><div><TextInput className="w-full" value={data.notes} placeholder="Catatan" onChange={(event) => setData('notes', event.target.value)} /><InputError message={errors.notes} className="mt-1" /></div><PrimaryButton disabled={processing}>Complete</PrimaryButton></form><ConfirmDialog show={showConfirm} message={`Complete ${item.planning_item?.name ?? 'item maintenance'} di KM ${data.completed_odo || '-'}?`} processing={processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></>;
}

function BreakdownInspectionForm({ unitId, currentOdo, plannings, onSuccess }: { unitId: number; currentOdo: number; plannings: UnitPlanning[]; onSuccess: () => void }) {
    const form = useForm({ unit_planning_id: plannings[0]?.id?.toString() ?? '', completed_odo: currentOdo.toString() });
    const [showConfirm, setShowConfirm] = useState(false);
    const selectedPlanning = plannings.find((planning) => planning.id.toString() === form.data.unit_planning_id);
    const submit = (event: FormEvent) => { event.preventDefault(); setShowConfirm(true); };
    const confirm = () => form.post(route('units.breakdown-inspection', unitId), { preserveScroll: true, onSuccess: () => { form.reset(); onSuccess(); }, onFinish: () => setShowConfirm(false) });

    return <section className="rounded-lg border border-red-200 bg-red-50 p-4"><h3 className="font-semibold text-red-900">Inspeksi setelah Breakdown</h3><form onSubmit={submit} className="mt-3 grid gap-3 md:grid-cols-3"><Select value={form.data.unit_planning_id} onValueChange={(value) => form.setData('unit_planning_id', value)}><SelectTrigger><SelectValue placeholder="Pilih item" /></SelectTrigger><SelectContent>{plannings.map((planning) => <SelectItem key={planning.id} value={String(planning.id)}>{planning.planning_item?.name}</SelectItem>)}</SelectContent></Select><TextInput type="number" value={form.data.completed_odo} onChange={(event) => form.setData('completed_odo', event.target.value)} /><PrimaryButton disabled={form.processing}>Simpan Inspeksi</PrimaryButton></form><ConfirmDialog show={showConfirm} message={`Simpan inspeksi Breakdown untuk ${selectedPlanning?.planning_item?.name ?? 'item maintenance'} di KM ${form.data.completed_odo || '-'}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} /></section>;
}

export default function Show({ auth, workOrder, planningItems, mechanics }: PageProps<{ workOrder: ResourceItem<WorkOrder>; planningItems: PlanningItem[]; mechanics: User[] }>) {
    const workOrderData = workOrder.data;
    const [activeItemForm, setActiveItemForm] = useState<ActiveItemForm>(null);
    const [showBreakdownForm, setShowBreakdownForm] = useState(false);
    const [showBreakdownInspectionForm, setShowBreakdownInspectionForm] = useState(true);
    const [breakdownInspectionMessage, setBreakdownInspectionMessage] = useState<string | null>(null);
    const [confirmAction, setConfirmAction] = useState<'approve' | 'reject' | 'blocked' | 'breakdown' | null>(null);
    const [blockedConfirmItemId, setBlockedConfirmItemId] = useState<number | null>(null);
    const hasSubmittedAction = (workOrderData.items ?? []).some((item) => ['pending_create', 'replace', 'postpone'].includes(item.status));
    const canApprove = auth.user.role === 'spv_ho' && hasSubmittedAction;
    const canCondition = ['superadmin', 'planner_area', 'mekanik'].includes(auth.user.role);
    const canSubmitAction = ['superadmin', 'planner_area'].includes(auth.user.role);
    const canReportFinding = ['planner_area', 'mekanik'].includes(auth.user.role);
    const inspectionPlannings = workOrderData.items?.filter((item) => item.status === 'breakdown').map((item) => item.unit_planning).filter(Boolean) as UnitPlanning[];
    const showBreakdownBanner = workOrderData.unit?.status === 'breakdown' || inspectionPlannings.length > 0;
    const showWarrantyBanner = Boolean(workOrderData.unit?.is_warranty);
    const isBreakdownLocked = workOrderData.unit?.status === 'breakdown';
    const breakdownLockedMessage = 'Aksi normal dikunci selama unit Breakdown. Input KM baru dan isi part yang diganti terlebih dahulu.';
    const blockedForm = useForm({ reason: '' });
    const breakdownForm = useForm({ reason: '' });
    const approve = () => router.post(route('work-orders.approve', workOrderData.id), {}, { preserveScroll: true, onFinish: () => setConfirmAction(null) });
    const reject = () => router.post(route('work-orders.reject', workOrderData.id), {}, { preserveScroll: true, onFinish: () => setConfirmAction(null) });
    const markBlocked = (event: FormEvent, itemId: number) => { event.preventDefault(); setBlockedConfirmItemId(itemId); setConfirmAction('blocked'); };
    const confirmBlocked = () => { if (blockedConfirmItemId) { blockedForm.post(route('work-order-items.blocked', blockedConfirmItemId), { preserveScroll: true, onSuccess: () => { blockedForm.reset(); setActiveItemForm(null); }, onFinish: () => { setConfirmAction(null); setBlockedConfirmItemId(null); } }); } };
    const markBreakdown = (event: FormEvent) => { event.preventDefault(); setConfirmAction('breakdown'); };
    const confirmBreakdown = () => { if (workOrderData.unit) { breakdownForm.post(route('units.breakdown', workOrderData.unit.id), { preserveScroll: true, onSuccess: () => { breakdownForm.reset(); setShowBreakdownForm(false); }, onFinish: () => setConfirmAction(null) }); } };
    const confirmMessage = confirmAction === 'approve' ? `Approve WO #${workOrderData.id} untuk ${workOrderData.unit?.current_plate ?? 'unit'}?` : confirmAction === 'reject' ? `Reject WO #${workOrderData.id} untuk ${workOrderData.unit?.current_plate ?? 'unit'}?` : confirmAction === 'blocked' ? `Submit Blocked untuk ${workOrderData.unit?.current_plate ?? 'unit'}?` : `Submit Breakdown untuk ${workOrderData.unit?.current_plate ?? 'unit'}?`;
    const confirmSubmit = () => { if (confirmAction === 'approve') { approve(); } if (confirmAction === 'reject') { reject(); } if (confirmAction === 'blocked') { confirmBlocked(); } if (confirmAction === 'breakdown') { confirmBreakdown(); } };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Detail Work Order</h2>}><Head title={`WO #${workOrderData.id}`} /><div className="py-10"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><div><Link href={route('work-orders.index')}><SecondaryButton>Kembali</SecondaryButton></Link></div>{breakdownInspectionMessage && <div role="status" className="rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">{breakdownInspectionMessage}</div>}<section className="rounded-xl border bg-card p-6 shadow-xs"><div className="flex flex-wrap items-start justify-between gap-4"><div><h3 className="text-base font-semibold text-foreground">WO #{workOrderData.id} - {workOrderData.unit?.current_plate}</h3><p className="text-sm text-muted-foreground">{workOrderData.site?.name} · {workOrderData.trigger_type} · {workOrderData.created_at?.slice(0, 10)}</p></div><div className="flex flex-wrap gap-2"><span className="rounded-full bg-indigo-50 px-3 py-1 text-sm font-medium text-indigo-700">{workOrderData.status}</span>{workOrderData.unit?.is_warranty && <span className="rounded-full bg-green-50 px-3 py-1 text-sm font-medium text-green-700">Warranty</span>}{workOrderData.unit?.status === 'breakdown' && <span className="rounded-full border border-red-200 bg-red-50 px-3 py-1 text-sm font-medium text-red-700 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-200 dark:text-red-200">Breakdown</span>}{workOrderData.trigger_type === 'manual' && <StatusBadge tone="blocked">Temuan Manual</StatusBadge>}</div></div><div className="mt-4 grid gap-3">{showBreakdownBanner && <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">Unit sedang Breakdown. Input KM baru untuk mengaktifkan kembali, lalu isi part yang diganti sebelum melanjutkan aksi lain.</div>}{showWarrantyBanner && <div className="rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm font-medium text-teal-800">Unit dalam masa Warranty. Koordinasikan penggantian ke dealer resmi.</div>}</div><div className="mt-6 flex flex-wrap gap-2">{canApprove && <PrimaryButton onClick={() => setConfirmAction('approve')}>Approve</PrimaryButton>}{canApprove && <SecondaryButton onClick={() => setConfirmAction('reject')}>Reject</SecondaryButton>}{canCondition && workOrderData.unit && <SecondaryButton onClick={() => setShowBreakdownForm(true)}>Breakdown</SecondaryButton>}</div>{showBreakdownForm && <ReasonForm title="Tandai Unit Breakdown" reason={breakdownForm.data.reason} setReason={(reason) => breakdownForm.setData('reason', reason)} error={breakdownForm.errors.reason} processing={breakdownForm.processing} onCancel={() => setShowBreakdownForm(false)} onSubmit={markBreakdown} />}</section>{canReportFinding && workOrderData.unit && <ManualFindingForm unitId={workOrderData.unit.id} planningItems={planningItems} mechanics={mechanics} />}{showBreakdownInspectionForm && workOrderData.unit?.status === 'active' && inspectionPlannings.length > 0 && <BreakdownInspectionForm unitId={workOrderData.unit.id} currentOdo={workOrderData.unit.current_odo} plannings={inspectionPlannings} onSuccess={() => { setShowBreakdownInspectionForm(false); setBreakdownInspectionMessage('Inspeksi breakdown tersimpan, cycle lanjut normal.'); }} />}<section className="space-y-4">{workOrderData.items?.map((item) => { const canComplete = canCondition && item.status === 'in_progress'; const canBlock = canCondition && ['on_hold', 'in_progress', 'overdue'].includes(item.status); const canReplaceOrPostpone = canSubmitAction && ['on_hold', 'blocked', 'overdue'].includes(item.status); const normalActionDisabled = isBreakdownLocked && (canReplaceOrPostpone || canBlock); return <article key={item.id} className="rounded-xl border bg-card p-6 shadow-xs"><div className="flex flex-wrap items-start justify-between gap-4"><div><h4 className="font-semibold text-foreground">{item.planning_item?.name}</h4><p className="text-sm text-muted-foreground">Due KM {(item.effective_due_km ?? item.unit_planning?.next_due_km)?.toLocaleString() ?? '-'} · Due Date {item.effective_due_date ?? item.unit_planning?.next_due_date ?? '-'}</p>{item.reason && <p className="mt-1 text-sm text-muted-foreground">Alasan: {item.reason}</p>}{item.status === 'pending_create' && <p className="mt-1 text-sm text-sky-700 dark:text-sky-200">Pengajuan Buat Task Sekarang menunggu approval SPV.</p>}{item.status === 'blocked' && <p className="mt-1 text-sm text-yellow-700 dark:text-yellow-200">Resolve dengan memilih Submit Replace atau Submit Postpone.</p>}{item.status === 'postpone' && <p className="mt-1 text-sm text-orange-700 dark:text-orange-200">Pengajuan due baru: KM {item.new_due_km?.toLocaleString() ?? '-'} · {item.new_due_date ?? '-'}</p>}{item.status === 'postponed' && <p className="mt-1 text-sm text-orange-700 dark:text-orange-200">Postpone disetujui ke KM {item.new_due_km?.toLocaleString() ?? '-'} · {item.new_due_date ?? '-'}</p>}</div><div className="flex flex-wrap gap-2"><span className="rounded-full bg-muted px-3 py-1 text-sm text-muted-foreground">{item.status}</span>{item.status === 'pending_create' && <span className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-sm font-medium text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-200">Menunggu Approval</span>}{item.status === 'replace' && <span className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-sm font-medium text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-200">Menunggu Approve Replace</span>}{item.status === 'postpone' && <span className="rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-sm font-medium text-orange-700 dark:border-orange-500/40 dark:bg-orange-500/15 dark:text-orange-200 dark:text-orange-200">Menunggu Approve Postpone</span>}{item.status === 'blocked' && <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm font-medium text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-200">Blocked</span>}{item.status === 'breakdown' && <span className="rounded-full border border-red-200 bg-red-50 px-3 py-1 text-sm font-medium text-red-700 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-200 dark:text-red-200">Breakdown</span>}</div></div>{item.completed_odo && <p className="mt-3 text-sm text-muted-foreground">Selesai pada {item.completed_date} di KM {item.completed_odo.toLocaleString()}</p>}<div className="mt-3 flex flex-wrap gap-2">{canReplaceOrPostpone && <PrimaryButton disabled={normalActionDisabled} title={normalActionDisabled ? breakdownLockedMessage : undefined} onClick={() => setActiveItemForm({ itemId: item.id, type: 'replace' })}>Submit Replace</PrimaryButton>}{canReplaceOrPostpone && <SecondaryButton disabled={normalActionDisabled} title={normalActionDisabled ? breakdownLockedMessage : undefined} onClick={() => setActiveItemForm({ itemId: item.id, type: 'postpone' })}>Submit Postpone</SecondaryButton>}{canBlock && <SecondaryButton disabled={normalActionDisabled} title={normalActionDisabled ? breakdownLockedMessage : undefined} onClick={() => setActiveItemForm({ itemId: item.id, type: 'blocked' })}>Blocked</SecondaryButton>}</div>{normalActionDisabled && <p className="mt-2 text-sm font-medium text-red-700 dark:text-red-200">{breakdownLockedMessage}</p>}{activeItemForm?.itemId === item.id && activeItemForm.type === 'replace' && <ReplaceForm item={item} workOrderId={workOrderData.id} mechanics={mechanics} onCancel={() => setActiveItemForm(null)} />}{activeItemForm?.itemId === item.id && activeItemForm.type === 'postpone' && <PostponeForm item={item} workOrderId={workOrderData.id} onCancel={() => setActiveItemForm(null)} />}{activeItemForm?.itemId === item.id && activeItemForm.type === 'blocked' && <ReasonForm title="Tandai Item Blocked" reason={blockedForm.data.reason} setReason={(reason) => blockedForm.setData('reason', reason)} error={blockedForm.errors.reason} processing={blockedForm.processing} onCancel={() => setActiveItemForm(null)} onSubmit={(event) => markBlocked(event, item.id)} />}{canComplete && workOrderData.unit && <CompleteItemForm item={item} workOrderId={workOrderData.id} currentOdo={workOrderData.unit.current_odo} />}</article>; })}{(!workOrderData.items || workOrderData.items.length === 0) && <div className="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">Belum ada item.</div>}</section></div></div><ConfirmDialog show={confirmAction !== null} message={confirmMessage} processing={blockedForm.processing || breakdownForm.processing} onCancel={() => setConfirmAction(null)} onConfirm={confirmSubmit} /></AuthenticatedLayout>;
}
