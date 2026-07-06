import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, ProjectionItem, ProjectionLine, ProjectionPart, ProjectionResult, Site } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type TabKey = 'unit' | 'item' | 'part';

interface ProjectionIndexProps extends PageProps {
    projection: ProjectionResult;
    sites: { data: Site[] };
    filters: {
        months: number;
        site_id: number | null;
    };
    permissions: {
        can_filter_site: boolean;
        can_view_unit: boolean;
        can_view_item: boolean;
        can_view_part: boolean;
        default_tab: TabKey;
    };
}

const formatDate = (date: string | null): string => {
    if (!date) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(date));
};

const formatKm = (value: number | null): string => (value === null ? '-' : `${new Intl.NumberFormat('id-ID').format(value)} KM`);

const projectionUrl = (months: number, siteId: number | null): string => {
    const params = new URLSearchParams({ months: String(months) });

    if (siteId) {
        params.set('site_id', String(siteId));
    }

    return `/projections?${params.toString()}`;
};

function DueRows({ items, showItem = true }: { items: ProjectionLine[]; showItem?: boolean }) {
    return <tbody className="divide-y divide-gray-200 bg-white">{items.map((item) => <tr key={`${item.unit_planning_id}-${item.planning_item_id}`}><td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900">{item.plate_number}{item.insufficient_data && <span className="ml-2 text-amber-500" title="Data inspeksi belum cukup">⚠</span>}</td><td className="whitespace-nowrap px-4 py-3 text-sm text-gray-600">{item.site_name}</td>{showItem && <td className="px-4 py-3 text-sm text-gray-700">{item.planning_item_name}</td>}<td className="whitespace-nowrap px-4 py-3 text-sm text-gray-600">{formatDate(item.estimated_due_date)}</td><td className="whitespace-nowrap px-4 py-3 text-sm text-gray-600">{formatKm(item.estimated_due_km)}</td></tr>)}</tbody>;
}

export default function Index({ projection, sites, filters, permissions }: ProjectionIndexProps) {
    const [activeTab, setActiveTab] = useState<TabKey>(permissions.default_tab);
    const tabs = useMemo(() => [
        permissions.can_view_unit ? { key: 'unit' as const, label: 'Per Unit' } : null,
        permissions.can_view_item ? { key: 'item' as const, label: 'Per Item' } : null,
        permissions.can_view_part ? { key: 'part' as const, label: 'Per Part' } : null,
    ].filter(Boolean) as { key: TabKey; label: string }[], [permissions]);

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Proyeksi Maintenance</h2>}><Head title="Proyeksi Maintenance" /><div className="py-12"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><div className="rounded-lg bg-white p-6 shadow-sm"><div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><div><h3 className="text-lg font-semibold text-gray-900">Kebutuhan 1-3 Bulan Ke Depan</h3><p className="mt-1 text-sm text-gray-600">Periode berakhir pada {formatDate(projection.period_end)}.</p></div><div className="flex flex-wrap gap-2">{[1, 2, 3].map((month) => <Link key={month} href={projectionUrl(month, filters.site_id)} preserveScroll aria-pressed={filters.months === month} className={`rounded-md px-4 py-2 text-sm font-medium ${filters.months === month ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`}>{month} Bulan</Link>)}</div></div>{permissions.can_filter_site && <div className="mt-5 max-w-sm"><label htmlFor="site_id" className="block text-sm font-medium text-gray-700">Filter Site</label><select id="site_id" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value={filters.site_id ?? ''} onChange={(event) => window.location.assign(projectionUrl(filters.months, event.target.value ? Number(event.target.value) : null))}><option value="">Semua Site</option>{sites.data.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select></div>}</div>{projection.warnings.length > 0 && <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"><strong>Perhatian:</strong> {projection.warnings.length} unit memiliki data inspeksi belum cukup untuk rata-rata KM yang kuat: {projection.warnings.map((warning) => warning.plate_number).join(', ')}.</div>}<div className="rounded-lg bg-white shadow-sm"><div className="border-b border-gray-200 px-6 pt-4"><nav className="flex gap-4" role="tablist" aria-label="Tampilan proyeksi">{tabs.map((tab) => <button key={tab.key} type="button" role="tab" aria-selected={activeTab === tab.key} onClick={() => setActiveTab(tab.key)} className={`border-b-2 px-1 py-3 text-sm font-medium ${activeTab === tab.key ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'}`}>{tab.label}</button>)}</nav></div><div className="p-6">{activeTab === 'unit' && <div className="space-y-5">{projection.by_unit.length === 0 && <p className="text-sm text-gray-500">Tidak ada item due pada periode ini.</p>}{projection.by_unit.map((unit) => <div key={unit.unit_id} className="overflow-hidden rounded-lg border border-gray-200"><div className="flex flex-col gap-1 bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><div className="font-semibold text-gray-900">{unit.plate_number}{unit.insufficient_data && <span className="ml-2 text-amber-500">⚠</span>}</div><div className="text-sm text-gray-600">{unit.site_name} · Avg {unit.avg_km_per_day} KM/hari · Est. odo {formatKm(unit.estimated_period_odo)}</div></div><div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200"><thead className="bg-white"><tr><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Plat Nomor</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Site</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Item</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Est. Due Date</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Est. Due KM</th></tr></thead><DueRows items={unit.items} /></table></div></div>)}</div>}{activeTab === 'item' && <div className="space-y-6">{projection.by_item.map((item: ProjectionItem) => <div key={item.planning_item_id} className="overflow-hidden rounded-lg border border-gray-200"><div className="bg-gray-50 px-4 py-3 font-semibold text-gray-900">{item.planning_item_name}</div><div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200"><thead className="bg-white"><tr><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Plat Nomor</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Site</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Est. Due Date</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Est. Due KM</th></tr></thead><DueRows items={item.items} showItem={false} /></table></div></div>)}{projection.by_item.length === 0 && <p className="text-sm text-gray-500">Tidak ada item due pada periode ini.</p>}</div>}{activeTab === 'part' && <div className="space-y-6"><p className="rounded-md bg-blue-50 p-3 text-sm text-blue-700">Quantity adalah estimasi dasar. Jumlah aktual diketahui saat mekanik eksekusi.</p>{projection.by_part.map((part: ProjectionPart) => <div key={part.planning_item_id} className="rounded-lg border border-gray-200 p-4"><h4 className="font-semibold text-gray-900">{part.planning_item_name}</h4><div className="mt-3 space-y-2">{part.items.map((item) => <div key={item.unit_planning_id} className="flex flex-col gap-1 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 sm:flex-row sm:items-center sm:gap-3"><span className="font-medium text-gray-900">{item.plate_number}</span><span className="hidden text-gray-300 sm:inline">│</span><span>{item.site_name}</span><span className="hidden text-gray-300 sm:inline">│</span><span>Due: {formatDate(item.estimated_due_date)}</span><span className="hidden text-gray-300 sm:inline">│</span><span>Est. qty: {item.estimated_quantity}</span></div>)}</div><p className="mt-3 text-sm font-semibold text-gray-800">Total estimasi: {part.total_estimated_quantity} unit</p></div>)}{projection.by_part.length === 0 && <p className="text-sm text-gray-500">Tidak ada part due pada periode ini.</p>}</div>}</div></div></div></div></AuthenticatedLayout>;
}
