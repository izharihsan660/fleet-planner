import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PlanningItem, Site, Unit, User, WorkOrder, WorkOrderPreviewItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface ResourceCollection<T> {
    data: T[];
}

const columns = [
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'preparation', label: 'Ancang-ancang' },
    { key: 'open', label: 'On Hold' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'complete', label: 'Complete' },
];

const dueClasses = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    yellow: 'bg-yellow-50 text-yellow-700 ring-yellow-100',
    red: 'bg-red-50 text-red-700 ring-red-100',
};

function itemSummary(names: string[] = []): string {
    if (names.length <= 2) {
        return names.join(', ') || '-';
    }

    return `${names.slice(0, 2).join(', ')} +${names.length - 2} lainnya`;
}

function AssignMechanicForm({ workOrder, mechanics, onCancel }: { workOrder: WorkOrder; mechanics: User[]; onCancel: () => void }) {
    const form = useForm({
        assigned_mechanic_id: workOrder.assigned_mechanic_id?.toString() ?? '',
        scheduled_date: workOrder.scheduled_date ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('work-orders.assign-mechanic', workOrder.id), { onSuccess: onCancel, preserveScroll: true });
    };

    return <form onSubmit={submit} className="mt-3 space-y-3 rounded-lg bg-gray-50 p-3"><select className="w-full rounded-md border-gray-300 text-sm shadow-sm" value={form.data.assigned_mechanic_id} onChange={(event) => form.setData('assigned_mechanic_id', event.target.value)}><option value="">Pilih mekanik</option>{mechanics.map((mechanic) => <option key={mechanic.id} value={mechanic.id}>{mechanic.name}</option>)}</select>{form.errors.assigned_mechanic_id && <p className="text-xs text-red-600">{form.errors.assigned_mechanic_id}</p>}<input className="w-full rounded-md border-gray-300 text-sm shadow-sm" type="date" value={form.data.scheduled_date} onChange={(event) => form.setData('scheduled_date', event.target.value)} />{form.errors.scheduled_date && <p className="text-xs text-red-600">{form.errors.scheduled_date}</p>}<div className="flex gap-2"><PrimaryButton disabled={form.processing}>Simpan</PrimaryButton><SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton></div></form>;
}

function PreviewCard({ item, canCreate }: { item: WorkOrderPreviewItem; canCreate: boolean }) {
    const createTask = () => router.post(route('unit-plannings.create-work-order', item.id), {}, { preserveScroll: true });

    return <article className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"><div className="flex items-start justify-between gap-3"><div><p className="font-semibold text-gray-900">{item.unit_plate}</p><p className="text-sm text-gray-500">{item.site_name}</p></div>{item.due && <span className={`rounded-full px-2 py-1 text-xs font-medium ring-1 ${dueClasses[item.due.level]}`}>{item.due.label}</span>}</div><p className="mt-3 text-sm font-medium text-gray-800">{item.planning_item_name}</p><p className="mt-1 text-sm text-gray-600">Due: {item.next_due_date ?? '-'} · KM {item.next_due_km?.toLocaleString() ?? '-'}</p>{item.approval_status === 'rejected' && <span className="mt-3 inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800">Rejected</span>}{canCreate && <PrimaryButton type="button" className="mt-3" onClick={createTask}>Buat Task Sekarang</PrimaryButton>}</article>;
}

function WorkOrderCard({ workOrder, mechanics, canAssign, assignId, setAssignId }: { workOrder: WorkOrder; mechanics: User[]; canAssign: boolean; assignId: number | null; setAssignId: (id: number | null) => void }) {
    const canShowAssign = canAssign && workOrder.status === 'in_progress' && workOrder.approved_at !== null;

    return <article className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-indigo-300"><Link href={route('work-orders.show', workOrder.id)} className="block"><div className="flex items-start justify-between gap-3"><div><p className="font-semibold text-gray-900">{workOrder.unit?.current_plate}</p><p className="text-sm text-gray-500">{workOrder.site?.name}</p></div><span className="rounded-full bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700">{workOrder.trigger_type}</span></div>{workOrder.sub_status && <span className="mt-3 inline-flex rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">{workOrder.sub_status.label}</span>}<p className="mt-3 text-sm text-gray-700">{itemSummary(workOrder.planning_item_names)}</p>{workOrder.nearest_due && <div className="mt-3 rounded-lg border border-gray-100 p-3 text-sm"><span className="block text-xs uppercase text-gray-400">Due Terdekat</span><span className="text-gray-700">{workOrder.nearest_due.next_due_date ?? '-'} · KM {workOrder.nearest_due.next_due_km?.toLocaleString() ?? '-'}</span><span className={`mt-2 inline-flex rounded-full px-2 py-1 text-xs font-medium ring-1 ${dueClasses[workOrder.nearest_due.level]}`}>{workOrder.nearest_due.label}</span></div>}<div className="mt-3 flex flex-wrap gap-2">{workOrder.unit?.is_warranty && <span className="rounded-full bg-cyan-50 px-2 py-1 text-xs font-medium text-cyan-700">Warranty</span>}{workOrder.unit?.status === 'breakdown' && <span className="rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700">Breakdown</span>}{workOrder.has_blocked_items && <span className="rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-700">Blocked</span>}{workOrder.has_high_usage_items && <span className="rounded-full bg-orange-50 px-2 py-1 text-xs font-medium text-orange-700">High Usage</span>}{workOrder.has_overdue_items && <span className="rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700">Overdue</span>}{workOrder.has_rejected_items && <span className="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800">Rejected</span>}</div></Link>{canShowAssign && <div className="mt-3"><SecondaryButton type="button" onClick={() => setAssignId(assignId === workOrder.id ? null : workOrder.id)}>Assign Mekanik</SecondaryButton>{assignId === workOrder.id && <AssignMechanicForm workOrder={workOrder} mechanics={mechanics} onCancel={() => setAssignId(null)} />}</div>}</article>;
}

export default function Index({ workOrders, upcomingItems, preparationItems, sites, units, mechanics, planningItems, filters, canCreateUpcomingTask, canAssignMechanic }: PageProps<{ workOrders: ResourceCollection<WorkOrder>; upcomingItems: WorkOrderPreviewItem[]; preparationItems: WorkOrderPreviewItem[]; sites: ResourceCollection<Site>; units: ResourceCollection<Unit>; mechanics: User[]; planningItems: PlanningItem[]; filters: { site_id?: string; status?: string; unit_id?: string; item_id?: string; assignee_id?: string }; canCreateUpcomingTask: boolean; canAssignMechanic: boolean }>) {
    const workOrderData = workOrders.data;
    const [siteId, setSiteId] = useState(filters.site_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [unitId, setUnitId] = useState(filters.unit_id ?? '');
    const [itemId, setItemId] = useState(filters.item_id ?? '');
    const [assigneeId, setAssigneeId] = useState(filters.assignee_id ?? '');
    const [assignId, setAssignId] = useState<number | null>(null);

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('work-orders.index'), { site_id: siteId || undefined, status: status || undefined, unit_id: unitId || undefined, item_id: itemId || undefined, assignee_id: assigneeId || undefined }, { preserveState: true });
    };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Work Orders</h2>}><Head title="Work Orders" /><div className="py-12"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><form onSubmit={filter} className="grid gap-4 bg-white p-4 shadow-sm sm:rounded-lg md:grid-cols-6"><select className="rounded-md border-gray-300 shadow-sm" value={siteId} onChange={(event) => setSiteId(event.target.value)}><option value="">Semua Site</option>{sites.data.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select><select className="rounded-md border-gray-300 shadow-sm" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Semua Status</option>{columns.filter((column) => !['upcoming', 'preparation'].includes(column.key)).map((column) => <option key={column.key} value={column.key}>{column.label}</option>)}</select><select className="rounded-md border-gray-300 shadow-sm" value={unitId} onChange={(event) => setUnitId(event.target.value)}><option value="">Semua Unit</option>{units.data.map((unit) => <option key={unit.id} value={unit.id}>{unit.current_plate}</option>)}</select><select className="rounded-md border-gray-300 shadow-sm" value={itemId} onChange={(event) => setItemId(event.target.value)}><option value="">Semua Item</option>{planningItems.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><select className="rounded-md border-gray-300 shadow-sm" value={assigneeId} onChange={(event) => setAssigneeId(event.target.value)}><option value="">Semua Assignee</option>{mechanics.map((mechanic) => <option key={mechanic.id} value={mechanic.id}>{mechanic.name}</option>)}</select><PrimaryButton>Filter</PrimaryButton></form><div className="grid gap-6 xl:grid-cols-5 lg:grid-cols-3">{columns.map((column) => { const cards = workOrderData.filter((workOrder) => workOrder.status === column.key); const previewCards = column.key === 'upcoming' ? upcomingItems : preparationItems; const count = ['upcoming', 'preparation'].includes(column.key) ? previewCards.length : cards.length; return <section key={column.key} className="rounded-lg bg-gray-50 p-4 shadow-sm"><div className="mb-4 flex items-center justify-between"><h3 className="font-semibold text-gray-900">{column.label}</h3><span className="rounded-full bg-white px-2 py-1 text-xs text-gray-600">{count}</span></div><div className="space-y-3">{column.key === 'upcoming' && previewCards.map((item) => <PreviewCard key={item.id} item={item} canCreate={canCreateUpcomingTask} />)}{column.key === 'preparation' && previewCards.map((item) => <PreviewCard key={item.id} item={item} canCreate={canCreateUpcomingTask} />)}{!['upcoming', 'preparation'].includes(column.key) && cards.map((workOrder) => <WorkOrderCard key={workOrder.id} workOrder={workOrder} mechanics={mechanics} canAssign={canAssignMechanic} assignId={assignId} setAssignId={setAssignId} />)}{count === 0 && <p className="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500">Belum ada item.</p>}</div></section>; })}</div></div></div></AuthenticatedLayout>;
}
