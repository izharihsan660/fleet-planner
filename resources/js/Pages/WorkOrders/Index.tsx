import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site, Unit, WorkOrder } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface ResourceCollection<T> {
    data: T[];
}

const columns = [
    { key: 'open', label: 'On Hold' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'complete', label: 'Complete' },
];

export default function Index({ workOrders, sites, units, filters }: PageProps<{ workOrders: ResourceCollection<WorkOrder>; sites: ResourceCollection<Site>; units: ResourceCollection<Unit>; filters: { site_id?: string; status?: string; unit_id?: string } }>) {
    const workOrderData = workOrders.data;
    const siteData = sites.data;
    const unitData = units.data;
    const [siteId, setSiteId] = useState(filters.site_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [unitId, setUnitId] = useState(filters.unit_id ?? '');

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('work-orders.index'), { site_id: siteId || undefined, status: status || undefined, unit_id: unitId || undefined }, { preserveState: true });
    };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Work Orders</h2>}><Head title="Work Orders" /><div className="py-12"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><form onSubmit={filter} className="grid gap-4 bg-white p-4 shadow-sm sm:rounded-lg md:grid-cols-4"><select className="rounded-md border-gray-300 shadow-sm" value={siteId} onChange={(event) => setSiteId(event.target.value)}><option value="">Semua Site</option>{siteData.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select><select className="rounded-md border-gray-300 shadow-sm" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Semua Status</option>{columns.map((column) => <option key={column.key} value={column.key}>{column.label}</option>)}</select><select className="rounded-md border-gray-300 shadow-sm" value={unitId} onChange={(event) => setUnitId(event.target.value)}><option value="">Semua Unit</option>{unitData.map((unit) => <option key={unit.id} value={unit.id}>{unit.current_plate}</option>)}</select><PrimaryButton>Filter</PrimaryButton></form><div className="grid gap-6 lg:grid-cols-3">{columns.map((column) => { const cards = workOrderData.filter((workOrder) => workOrder.status === column.key); return <section key={column.key} className="rounded-lg bg-gray-50 p-4 shadow-sm"><div className="mb-4 flex items-center justify-between"><h3 className="font-semibold text-gray-900">{column.label}</h3><span className="rounded-full bg-white px-2 py-1 text-xs text-gray-600">{cards.length}</span></div><div className="space-y-3">{cards.map((workOrder) => <Link key={workOrder.id} href={route('work-orders.show', workOrder.id)} className="block rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-indigo-300"><div className="flex items-start justify-between gap-3"><div><p className="font-semibold text-gray-900">{workOrder.unit?.current_plate}</p><p className="text-sm text-gray-500">{workOrder.site?.name}</p></div><span className="rounded-full bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700">{workOrder.trigger_type}</span></div><div className="mt-3 flex flex-wrap gap-2">{workOrder.unit?.status === 'breakdown' && <span className="rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700">Breakdown</span>}{workOrder.has_blocked_items && <span className="rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-700">Blocked</span>}{workOrder.has_high_usage_items && <span className="rounded-full bg-orange-50 px-2 py-1 text-xs font-medium text-orange-700">High Usage</span>}</div><div className="mt-4 grid grid-cols-2 gap-3 text-sm text-gray-600"><div><span className="block text-xs uppercase text-gray-400">Items</span>{workOrder.items_count ?? workOrder.items?.length ?? 0}</div><div><span className="block text-xs uppercase text-gray-400">Tanggal</span>{workOrder.created_at?.slice(0, 10)}</div></div></Link>)}{cards.length === 0 && <p className="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500">Belum ada WO.</p>}</div></section>; })}</div></div></div></AuthenticatedLayout>;
}
