import PaginationLinks from '@/Components/PaginationLinks';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table as UiTable, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { PageProps, PaginatedCollection, ReportSummary, Site } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ReactNode, useMemo, useState } from 'react';

type ReportsPageProps = PageProps<{
    summary: ReportSummary;
    woSummary: PaginatedCollection<ReportSummary>;
    byItem: PaginatedCollection<ReportSummary>;
    byUnit: PaginatedCollection<ReportSummary>;
    overdueByArea: PaginatedCollection<ReportSummary>;
    sites: { data: Site[] };
    filters: { month: number; year: number; site_id: number | null; tab: 'wo' | 'item' | 'unit' | 'overdue' };
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
        permissions.can_view_overdue ? { key: 'overdue', label: `Overdue — ${(summary.total_overdue ?? 0).toLocaleString('id-ID')}` } : null,
    ].filter(Boolean) as { key: 'wo' | 'item' | 'unit' | 'overdue'; label: string }[], [permissions, summary.total_overdue]);
    const [activeTab, setActiveTab] = useState(tabs.find((tab) => tab.key === permissions.default_tab)?.key ?? tabs[0]?.key ?? 'item');

    const reportUrl = (month: number, year: number, siteId: string) => `${route('reports.index')}?month=${month}&year=${year}${siteId ? `&site_id=${siteId}` : ''}`;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Laporan & History</h2>}>
            <Head title="Laporan" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <SummaryCard label="Total WO" value={summary.total_wo ?? 0} />
                        <SummaryCard label="Total Item" value={summary.total_items ?? 0} />
                        <SummaryCard label="Complete" value={summary.total_complete ?? 0} />
                        <SummaryCard label="Overdue" value={summary.total_overdue ?? 0} tone="danger" />
                    </div>

                    <Card>
                        <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <FilterSelect label="Bulan" value={filters.month} onChange={(value) => window.location.assign(reportUrl(Number(value), filters.year, filters.site_id?.toString() ?? ''))} options={months.map((month) => ({ value: month, label: month.toString().padStart(2, '0') }))} />
                            <FilterSelect label="Tahun" value={filters.year} onChange={(value) => window.location.assign(reportUrl(filters.month, Number(value), filters.site_id?.toString() ?? ''))} options={[filters.year - 1, filters.year, filters.year + 1].map((year) => ({ value: year, label: String(year) }))} />
                            {permissions.can_filter_site && <FilterSelect label="Site" value={filters.site_id ?? ''} onChange={(value) => window.location.assign(reportUrl(filters.month, filters.year, String(value)))} options={[{ value: '', label: 'Semua Site' }, ...sites.data.map((site) => ({ value: site.id, label: site.name }))]} />}
                        </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent>
                            <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as typeof activeTab)}>
                                <TabsList>
                                    {tabs.map((tab) => <TabsTrigger key={tab.key} value={tab.key}>{tab.label}</TabsTrigger>)}
                                </TabsList>
                                <TabsContent value="wo"><DataTable headers={['Site', 'Total WO', 'Total Item', 'Complete', 'Overdue', 'In Progress']} rows={woSummary.data.map((row) => [row.site, row.total_wo, row.total_item, row.complete, row.overdue, row.in_progress])} meta={woSummary.meta} /></TabsContent>
                                <TabsContent value="item"><DataTable headers={['Item', 'Total WO', 'Complete', 'Overdue', 'Avg Hari Penyelesaian']} rows={byItem.data.map((row) => [row.item, row.total_wo, row.total_complete, row.total_overdue, row.avg_hari_penyelesaian])} meta={byItem.meta} /></TabsContent>
                                <TabsContent value="unit"><DataTable headers={['Plat Nomor', 'Site', 'Total WO', 'Complete', 'Overdue']} rows={byUnit.data.map((row) => [row.unit_id ? <Link className="font-medium text-primary hover:underline" href={route('units.history', row.unit_id)}>{row.plat_nomor}</Link> : row.plat_nomor, row.site, row.total_wo, row.total_complete, row.total_overdue])} meta={byUnit.meta} /></TabsContent>
                                <TabsContent value="overdue"><DataTable headers={['Site', 'Total Overdue', 'Item Overdue']} rows={overdueByArea.data.map((row) => [row.site, row.total_overdue, row.items?.join(', ') || '-'])} meta={overdueByArea.meta} /></TabsContent>
                            </Tabs>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryCard({ label, value, tone = 'default' }: { label: string; value: number; tone?: 'default' | 'danger' }) {
    return <Card><CardContent><div className="text-sm text-muted-foreground">{label}</div><div className={`mt-2 text-3xl font-semibold ${tone === 'danger' ? 'text-destructive' : 'text-foreground'}`}>{value}</div></CardContent></Card>;
}

function FilterSelect({ label, value, onChange, options }: { label: string; value: number | string; onChange: (value: string | number) => void; options: { value: number | string; label: string }[] }) {
    return <div className="space-y-2"><Label>{label}</Label><Select value={String(value || 'all')} onValueChange={(selected) => onChange(selected === 'all' ? '' : selected)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{options.map((option) => <SelectItem key={option.value || 'all'} value={String(option.value || 'all')}>{option.label}</SelectItem>)}</SelectContent></Select></div>;
}

function DataTable({ headers, rows, meta }: { headers: string[]; rows: ReactNode[][]; meta: PaginatedCollection<ReportSummary>['meta'] }) {
    return <div className="space-y-4"><div className="mt-4 overflow-x-auto"><UiTable><TableHeader><TableRow>{headers.map((header) => <TableHead key={header}>{header}</TableHead>)}</TableRow></TableHeader><TableBody>{rows.length === 0 && <TableRow><TableCell className="py-6 text-muted-foreground" colSpan={headers.length}>Tidak ada data.</TableCell></TableRow>}{rows.map((row, rowIndex) => <TableRow key={rowIndex}>{row.map((cell, cellIndex) => <TableCell key={cellIndex}>{cell}</TableCell>)}</TableRow>)}</TableBody></UiTable></div><PaginationLinks meta={meta} /></div>;
}
