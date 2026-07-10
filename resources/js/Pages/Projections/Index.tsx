import PaginationLinks from '@/Components/PaginationLinks';
import StatusBadge from '@/Components/StatusBadge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, ProjectionItem, ProjectionLine, ProjectionPart, ProjectionResult, Site } from '@/types';
import { Head } from '@inertiajs/react';
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
    return (
        <TableBody>
            {items.map((item) => (
                <TableRow key={`${item.unit_planning_id}-${item.planning_item_id}`}>
                    <TableCell className="font-medium text-foreground">
                        {item.plate_number}
                        {item.insufficient_data && <span className="ml-2 text-amber-500" title={item.data_status_message ?? 'Data inspeksi belum cukup'}>⚠</span>}
                    </TableCell>
                    <TableCell>{item.site_name}</TableCell>
                    {showItem && <TableCell>{item.planning_item_name}</TableCell>}
                    <TableCell>{formatDate(item.estimated_due_date)}</TableCell>
                    <TableCell>{formatKm(item.estimated_due_km)}</TableCell>
                </TableRow>
            ))}
        </TableBody>
    );
}

function DueTable({ items, showItem = true }: { items: ProjectionLine[]; showItem?: boolean }) {
    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Plat Nomor</TableHead>
                        <TableHead>Site</TableHead>
                        {showItem && <TableHead>Item</TableHead>}
                        <TableHead>Est. Due Date</TableHead>
                        <TableHead>Est. Due KM</TableHead>
                    </TableRow>
                </TableHeader>
                <DueRows items={items} showItem={showItem} />
            </Table>
        </div>
    );
}

export default function Index({ projection, sites, filters, permissions }: ProjectionIndexProps) {
    const [activeTab, setActiveTab] = useState<TabKey>(permissions.default_tab);
    const [showWarningList, setShowWarningList] = useState(false);
    const tabs = useMemo(() => [
        permissions.can_view_unit ? { key: 'unit' as const, label: 'Per Unit' } : null,
        permissions.can_view_item ? { key: 'item' as const, label: 'Per Item' } : null,
        permissions.can_view_part ? { key: 'part' as const, label: 'Per Part' } : null,
    ].filter(Boolean) as { key: TabKey; label: string }[], [permissions]);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Proyeksi Maintenance</h2>}>
            <Head title="Proyeksi Maintenance" />
            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <Card>
                        <CardHeader className="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center">
                            <div>
                                <CardTitle>Kebutuhan 1-3 Bulan Ke Depan</CardTitle>
                                <CardDescription>Periode berakhir pada {formatDate(projection.period_end)}.</CardDescription>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Periode</Label>
                                    <Select value={String(filters.months)} onValueChange={(value) => window.location.assign(projectionUrl(Number(value), filters.site_id))}>
                                        <SelectTrigger className="min-w-40"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {[1, 2, 3].map((month) => <SelectItem key={month} value={String(month)}>{month} Bulan</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {permissions.can_filter_site && (
                                    <div className="space-y-2">
                                        <Label>Filter Lokasi</Label>
                                        <Select value={filters.site_id ? String(filters.site_id) : 'all'} onValueChange={(value) => window.location.assign(projectionUrl(filters.months, value === 'all' ? null : Number(value)))}>
                                            <SelectTrigger className="min-w-48"><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Semua Lokasi</SelectItem>
                                                {sites.data.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                            </div>
                        </CardHeader>
                    </Card>

                    {projection.warnings.length > 0 && (
                        <div className="space-y-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 shadow-xs dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <strong>Perhatian:</strong> {projection.warnings.length.toLocaleString('id-ID')} unit belum ada data KM — menunggu input Mekanik.
                                    <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">Daftar plat disembunyikan agar halaman tetap mudah dibaca.</p>
                                </div>
                                <button type="button" onClick={() => setShowWarningList((value) => !value)} className="inline-flex min-h-9 items-center justify-center rounded-md border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100 dark:hover:bg-amber-900">
                                    {showWarningList ? 'Sembunyikan daftar' : 'Lihat daftar'}
                                </button>
                            </div>
                            {showWarningList && (
                                <div className="max-h-80 overflow-y-auto rounded-lg border border-amber-200 bg-white dark:border-amber-900 dark:bg-background">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Plat Nomor</TableHead>
                                                <TableHead>Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {projection.warnings.map((warning) => (
                                                <TableRow key={warning.plate_number}>
                                                    <TableCell className="font-medium text-foreground">{warning.plate_number}</TableCell>
                                                    <TableCell>Data KM belum tersedia — menunggu input Mekanik</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </div>
                    )}

                    <Card>
                        <CardContent>
                            <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as TabKey)}>
                                <TabsList>
                                    {tabs.map((tab) => <TabsTrigger key={tab.key} value={tab.key}>{tab.label}</TabsTrigger>)}
                                </TabsList>
                                <TabsContent value="unit" className="space-y-5">
                                    {projection.by_unit.data.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada item due pada periode ini.</p>}
                                    {projection.by_unit.data.map((unit) => (
                                        <Card key={unit.unit_id} className="shadow-xs">
                                            <CardHeader className="flex-row items-center justify-between gap-4 space-y-0 bg-muted/30">
                                                <div>
                                                    <CardTitle className="text-base">{unit.plate_number}{unit.insufficient_data && <span className="ml-2 text-amber-500" title={unit.data_status_message ?? 'Data inspeksi belum cukup'}>⚠</span>}</CardTitle>
                                                    <CardDescription>{unit.site_name} · Avg {unit.avg_km_per_day} KM/hari · Est. odo {formatKm(unit.estimated_period_odo)}</CardDescription>
                                                    {unit.data_status_message && <p className="mt-2 text-xs font-medium text-amber-600 dark:text-amber-300">{unit.data_status_message}</p>}
                                                </div>
                                                <StatusBadge>{unit.items.length} item</StatusBadge>
                                            </CardHeader>
                                            <CardContent><DueTable items={unit.items} /></CardContent>
                                        </Card>
                                    ))}
                                    <PaginationLinks meta={projection.by_unit.meta} />
                                </TabsContent>
                                <TabsContent value="item" className="space-y-5">
                                    {projection.by_item.data.map((item: ProjectionItem) => (
                                        <Card key={item.planning_item_id} className="shadow-xs">
                                            <CardHeader className="bg-muted/30"><CardTitle className="text-base">{item.planning_item_name}</CardTitle></CardHeader>
                                            <CardContent><DueTable items={item.items} showItem={false} /></CardContent>
                                        </Card>
                                    ))}
                                    {projection.by_item.data.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada item due pada periode ini.</p>}
                                    <PaginationLinks meta={projection.by_item.meta} />
                                </TabsContent>
                                <TabsContent value="part" className="space-y-5">
                                    <p className="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-700">Quantity adalah estimasi dasar. Jumlah aktual diketahui saat mekanik eksekusi.</p>
                                    {projection.by_part.data.map((part: ProjectionPart) => (
                                        <Card key={part.planning_item_id} className="shadow-xs">
                                            <CardHeader>
                                                <CardTitle className="text-base">{part.planning_item_name}</CardTitle>
                                                <CardDescription>Total estimasi: {part.total_estimated_quantity} unit</CardDescription>
                                            </CardHeader>
                                            <CardContent className="space-y-2">
                                                {part.items.map((item) => (
                                                    <div key={item.unit_planning_id} className="grid gap-1 rounded-lg bg-muted/40 px-3 py-2 text-sm text-muted-foreground sm:grid-cols-4 sm:items-center">
                                                        <span className="font-medium text-foreground">{item.plate_number}</span>
                                                        <span>{item.site_name}</span>
                                                        <span>Due: {formatDate(item.estimated_due_date)}</span>
                                                        <span>Est. qty: {item.estimated_quantity}</span>
                                                    </div>
                                                ))}
                                            </CardContent>
                                        </Card>
                                    ))}
                                    {projection.by_part.data.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada part due pada periode ini.</p>}
                                    <PaginationLinks meta={projection.by_part.meta} />
                                </TabsContent>
                            </Tabs>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
