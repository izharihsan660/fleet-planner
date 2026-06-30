import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, ReportSummary, Site } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ReactNode, useMemo, useState } from 'react';

type ReportsPageProps = PageProps<{
    summary: ReportSummary;
    woSummary: ReportSummary[];
    byItem: ReportSummary[];
    byUnit: ReportSummary[];
    overdueByArea: ReportSummary[];
    sites: { data: Site[] };
    filters: { month: number; year: number; site_id: number | null };
    permissions: {
        can_filter_site: boolean;
        can_view_wo_summary: boolean;
        can_view_by_item: boolean;
        can_view_by_unit: boolean;
        can_view_overdue: boolean;
        default_tab: 'wo' | 'item' | 'unit' | 'overdue';
    };
}>;

const months = Array.from({ length: 12 }, (_, index) => index + 1);

export default function Index({ summary, woSummary, byItem, byUnit, overdueByArea, sites, filters, permissions }: ReportsPageProps) {
    const tabs = useMemo(() => [
        permissions.can_view_wo_summary ? { key: 'wo', label: 'Rekap WO' } : null,
        permissions.can_view_by_item ? { key: 'item', label: 'Per Item' } : null,
        permissions.can_view_by_unit ? { key: 'unit', label: 'Per Unit' } : null,
        permissions.can_view_overdue ? { key: 'overdue', label: 'Overdue' } : null,
    ].filter(Boolean) as { key: 'wo' | 'item' | 'unit' | 'overdue'; label: string }[], [permissions]);
    const [activeTab, setActiveTab] = useState(tabs.find((tab) => tab.key === permissions.default_tab)?.key ?? tabs[0]?.key ?? 'item');

    const reportUrl = (month: number, year: number, siteId: string) => `${route('reports.index')}?month=${month}&year=${year}${siteId ? `&site_id=${siteId}` : ''}`;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Laporan & History</h2>}>
            <Head title="Laporan" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <SummaryCard label="Total WO" value={summary.total_wo ?? 0} />
                        <SummaryCard label="Total Item" value={summary.total_items ?? 0} />
                        <SummaryCard label="Complete" value={summary.total_complete ?? 0} />
                        <SummaryCard label="Overdue" value={summary.total_overdue ?? 0} tone="danger" />
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <FilterSelect label="Bulan" value={filters.month} onChange={(value) => window.location.assign(reportUrl(Number(value), filters.year, filters.site_id?.toString() ?? ''))} options={months.map((month) => ({ value: month, label: month.toString().padStart(2, '0') }))} />
                            <FilterSelect label="Tahun" value={filters.year} onChange={(value) => window.location.assign(reportUrl(filters.month, Number(value), filters.site_id?.toString() ?? ''))} options={[filters.year - 1, filters.year, filters.year + 1].map((year) => ({ value: year, label: String(year) }))} />
                            {permissions.can_filter_site && <FilterSelect label="Site" value={filters.site_id ?? ''} onChange={(value) => window.location.assign(reportUrl(filters.month, filters.year, String(value)))} options={[{ value: '', label: 'Semua Site' }, ...sites.data.map((site) => ({ value: site.id, label: site.name }))]} />}
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div className="border-b border-gray-200 px-6 pt-4">
                            <nav className="flex gap-4" aria-label="Tabs">
                                {tabs.map((tab) => <button key={tab.key} type="button" onClick={() => setActiveTab(tab.key)} className={`border-b-2 px-1 py-3 text-sm font-medium ${activeTab === tab.key ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'}`}>{tab.label}</button>)}
                            </nav>
                        </div>

                        <div className="p-6">
                            {activeTab === 'wo' && <Table headers={['Site', 'Total WO', 'Total Item', 'Complete', 'Overdue', 'In Progress']} rows={woSummary.map((row) => [row.site, row.total_wo, row.total_item, row.complete, row.overdue, row.in_progress])} />}
                            {activeTab === 'item' && <Table headers={['Item', 'Total WO', 'Complete', 'Overdue', 'Avg Hari Penyelesaian']} rows={byItem.map((row) => [row.item, row.total_wo, row.total_complete, row.total_overdue, row.avg_hari_penyelesaian])} />}
                            {activeTab === 'unit' && <Table headers={['Plat Nomor', 'Site', 'Total WO', 'Complete', 'Overdue']} rows={byUnit.map((row) => [row.unit_id ? <Link className="font-medium text-indigo-600 hover:text-indigo-800" href={route('units.history', row.unit_id)}>{row.plat_nomor}</Link> : row.plat_nomor, row.site, row.total_wo, row.total_complete, row.total_overdue])} />}
                            {activeTab === 'overdue' && <Table headers={['Site', 'Total Overdue', 'Item Overdue']} rows={overdueByArea.map((row) => [row.site, row.total_overdue, row.items?.join(', ') || '-'])} />}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryCard({ label, value, tone = 'default' }: { label: string; value: number; tone?: 'default' | 'danger' }) {
    return <div className="rounded-lg bg-white p-5 shadow-sm"><div className="text-sm text-gray-500">{label}</div><div className={`mt-2 text-3xl font-semibold ${tone === 'danger' ? 'text-red-600' : 'text-gray-900'}`}>{value}</div></div>;
}

function FilterSelect({ label, value, onChange, options }: { label: string; value: number | string; onChange: (value: string | number) => void; options: { value: number | string; label: string }[] }) {
    return <label className="block text-sm font-medium text-gray-700">{label}<select className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option key={option.value || 'all'} value={option.value}>{option.label}</option>)}</select></label>;
}

function Table({ headers, rows }: { headers: string[]; rows: ReactNode[][] }) {
    return <div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200"><thead className="bg-gray-50"><tr>{headers.map((header) => <th key={header} className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{header}</th>)}</tr></thead><tbody className="divide-y divide-gray-200 bg-white">{rows.length === 0 && <tr><td className="px-4 py-4 text-sm text-gray-500" colSpan={headers.length}>Tidak ada data.</td></tr>}{rows.map((row, rowIndex) => <tr key={rowIndex}>{row.map((cell, cellIndex) => <td key={cellIndex} className="px-4 py-3 text-sm text-gray-700">{cell}</td>)}</tr>)}</tbody></table></div>;
}
