import PaginationLinks from '@/Components/PaginationLinks';
import StatusBadge from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, ProjectionItem, ProjectionLine, ProjectionPart, ProjectionResult, Region, Site } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type TabKey = 'unit' | 'item' | 'calendar' | 'part';

type CalendarItem = {
    id: number;
    work_order_id: number;
    site_id: number;
    site_name: string;
    plate_number: string;
    item_name: string;
    status: string;
    due_date: string;
    due_km: number | null;
    late_days: number;
    status_label: string;
    is_overdue: boolean;
    is_high_usage: boolean;
};

interface ProjectionIndexProps extends PageProps {
    projection: ProjectionResult;
    sites: { data: Site[] };
    regions: { data: Region[] };
    calendar: {
        month: string;
        label: string;
        items: CalendarItem[];
        summary_by_date: Record<string, { total: number; overdue: number; high_usage: number }>;
    };
    filters: {
        months: number;
        site_id: number | null;
        month: string;
        region_id: number | null;
    };
    permissions: {
        can_filter_site: boolean;
        can_filter_region: boolean;
        can_view_unit: boolean;
        can_view_item: boolean;
        can_view_calendar: boolean;
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

const projectionUrl = (months: number, siteId: number | null, month: string, regionId: number | null): string => {
    const params = new URLSearchParams({ months: String(months) });

    if (siteId) {
        params.set('site_id', String(siteId));
    }

    if (month) {
        params.set('month', month);
    }

    if (regionId) {
        params.set('region_id', String(regionId));
    }

    return `/projections?${params.toString()}`;
};

const addMonths = (month: string, amount: number): string => {
    const [year, monthIndex] = month.split('-').map(Number);
    const date = new Date(year, monthIndex - 1 + amount, 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
};

const todayMonth = (): string => {
    const today = new Date();

    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
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

function MonthCalendar({ calendar, selectedDate, onSelectDate }: { calendar: ProjectionIndexProps['calendar']; selectedDate: string | null; onSelectDate: (date: string) => void }) {
    const [year, monthNumber] = calendar.month.split('-').map(Number);
    const firstDate = new Date(year, monthNumber - 1, 1);
    const firstGridDate = new Date(firstDate);
    firstGridDate.setDate(firstDate.getDate() - firstDate.getDay());

    const dates = Array.from({ length: 42 }, (_, index) => {
        const date = new Date(firstGridDate);
        date.setDate(firstGridDate.getDate() + index);

        return date;
    });

    const toDateKey = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    return (
        <div className="overflow-hidden rounded-xl border">
            <div className="grid grid-cols-7 bg-muted/40 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'].map((day) => <div key={day} className="p-3">{day}</div>)}
            </div>
            <div className="grid grid-cols-7">
                {dates.map((date) => {
                    const dateKey = toDateKey(date);
                    const summary = calendar.summary_by_date[dateKey];
                    const isCurrentMonth = date.getMonth() === monthNumber - 1;
                    const isSelected = selectedDate === dateKey;
                    const hasTasks = Boolean(summary?.total);
                    const tone = summary?.overdue ? 'border-red-300 bg-red-50 text-red-900' : summary?.high_usage ? 'border-amber-300 bg-amber-50 text-amber-900' : hasTasks ? 'border-sky-200 bg-sky-50 text-sky-900' : 'border-border bg-card';

                    return (
                        <button
                            key={dateKey}
                            type="button"
                            disabled={!hasTasks}
                            onClick={() => onSelectDate(dateKey)}
                            className={`min-h-24 border p-2 text-left transition ${tone} ${isCurrentMonth ? '' : 'opacity-40'} ${isSelected ? 'ring-2 ring-primary ring-offset-1' : ''} ${hasTasks ? 'hover:brightness-95' : 'cursor-default'}`}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <span className="text-sm font-semibold">{date.getDate()}</span>
                                {hasTasks && <span className="rounded-full bg-foreground px-2 py-0.5 text-xs font-semibold text-background">{summary.total}</span>}
                            </div>
                            {summary?.overdue ? <span className="mt-3 inline-flex rounded-full bg-red-600 px-2 py-0.5 text-xs font-medium text-white">Overdue</span> : null}
                            {!summary?.overdue && summary?.high_usage ? <span className="mt-3 inline-flex rounded-full bg-amber-500 px-2 py-0.5 text-xs font-medium text-white">High Usage</span> : null}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export default function Index({ projection, sites, regions, calendar, filters, permissions }: ProjectionIndexProps) {
    const [activeTab, setActiveTab] = useState<TabKey>(permissions.default_tab);
    const [showWarningList, setShowWarningList] = useState(false);
    const [selectedDate, setSelectedDate] = useState<string | null>(null);
    const tabs = useMemo(() => [
        permissions.can_view_unit ? { key: 'unit' as const, label: 'Per Unit' } : null,
        permissions.can_view_item ? { key: 'item' as const, label: 'Per Item' } : null,
        permissions.can_view_calendar ? { key: 'calendar' as const, label: 'Kalender' } : null,
        permissions.can_view_part ? { key: 'part' as const, label: 'Per Part' } : null,
    ].filter(Boolean) as { key: TabKey; label: string }[], [permissions]);

    const selectedItems = useMemo(() => calendar.items.filter((item) => item.due_date === selectedDate), [calendar.items, selectedDate]);
    const navigateProjection = (nextFilters: Partial<ProjectionIndexProps['filters']>) => {
        const next = { ...filters, ...nextFilters };
        router.get(projectionUrl(next.months, next.site_id, next.month, next.region_id), {}, { preserveScroll: true, preserveState: true, replace: true });
    };

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
                                    <Select value={String(filters.months)} onValueChange={(value) => navigateProjection({ months: Number(value) })}>
                                        <SelectTrigger className="min-w-40"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {[1, 2, 3].map((month) => <SelectItem key={month} value={String(month)}>{month} Bulan</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {permissions.can_filter_site && (
                                    <div className="space-y-2">
                                        <Label>Filter Lokasi</Label>
                                        <Select value={filters.site_id ? String(filters.site_id) : 'all'} onValueChange={(value) => navigateProjection({ site_id: value === 'all' ? null : Number(value) })}>
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
                                <TabsContent value="calendar" className="space-y-5">
                                    <div className="flex flex-col gap-4 rounded-xl border bg-muted/20 p-4 lg:flex-row lg:items-end lg:justify-between">
                                        <div>
                                            <h3 className="text-lg font-semibold text-foreground">Month View: {calendar.label}</h3>
                                            <p className="text-sm text-muted-foreground">Peta beban kerja harian dari task Work Order yang belum complete/cancelled.</p>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[180px_180px_140px_140px_120px]">
                                            {permissions.can_filter_region && (
                                                <div className="space-y-2">
                                                    <Label>Region</Label>
                                                    <Select value={filters.region_id ? String(filters.region_id) : 'all'} onValueChange={(value) => navigateProjection({ region_id: value === 'all' ? null : Number(value), site_id: null })}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="all">All Region</SelectItem>
                                                            {regions.data.map((region) => <SelectItem key={region.id} value={String(region.id)}>{region.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            )}
                                            {permissions.can_filter_site && (
                                                <div className="space-y-2">
                                                    <Label>Site</Label>
                                                    <Select value={filters.site_id ? String(filters.site_id) : 'all'} onValueChange={(value) => navigateProjection({ site_id: value === 'all' ? null : Number(value) })}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="all">Semua Site</SelectItem>
                                                            {sites.data.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            )}
                                            <Button type="button" variant="outline" className="self-end" onClick={() => navigateProjection({ month: addMonths(filters.month, -1) })}>← Prev</Button>
                                            <Button type="button" variant="outline" className="self-end" onClick={() => navigateProjection({ month: addMonths(filters.month, 1) })}>Next →</Button>
                                            <Button type="button" className="self-end" onClick={() => navigateProjection({ month: todayMonth() })}>Hari Ini</Button>
                                        </div>
                                    </div>
                                    <MonthCalendar calendar={calendar} selectedDate={selectedDate} onSelectDate={setSelectedDate} />
                                    {calendar.items.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada task nyata pada bulan ini.</p>}
                                    {selectedDate && selectedItems.length > 0 && (
                                        <>
                                            <Button type="button" aria-label="Tutup detail tanggal" variant="ghost" className="fixed inset-0 z-30 h-auto rounded-none bg-black/20 p-0 hover:bg-black/20" onClick={() => setSelectedDate(null)} />
                                            <div className="fixed inset-x-0 bottom-0 z-40 rounded-t-2xl border bg-background shadow-2xl">
                                                <div className="mx-auto max-h-[78vh] max-w-7xl overflow-y-auto p-5">
                                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                        <div>
                                                            <h4 className="font-semibold text-foreground">Detail Task — {formatDate(selectedDate)}</h4>
                                                            <p className="text-sm text-muted-foreground">{selectedItems.length} task pada tanggal ini. Kalender hanya untuk monitoring.</p>
                                                        </div>
                                                        <Button type="button" variant="outline" onClick={() => setSelectedDate(null)}>Tutup</Button>
                                                    </div>
                                                    <div className="mt-4 overflow-x-auto">
                                                        <Table>
                                                            <TableHeader>
                                                                <TableRow>
                                                                    <TableHead>Unit</TableHead>
                                                                    <TableHead>Item Maintenance</TableHead>
                                                                    <TableHead>Site</TableHead>
                                                                    <TableHead>Status</TableHead>
                                                                    <TableHead>Arahkan</TableHead>
                                                                </TableRow>
                                                            </TableHeader>
                                                            <TableBody>
                                                                {selectedItems.map((item) => (
                                                                    <TableRow key={item.id}>
                                                                        <TableCell className="font-medium text-foreground">{item.plate_number}</TableCell>
                                                                        <TableCell>{item.item_name}</TableCell>
                                                                        <TableCell>{item.site_name}</TableCell>
                                                                        <TableCell>
                                                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${item.is_overdue ? 'bg-red-100 text-red-700' : item.is_high_usage ? 'bg-amber-100 text-amber-700' : 'bg-muted text-muted-foreground'}`}>
                                                                                {item.status_label || item.status}
                                                                            </span>
                                                                        </TableCell>
                                                                        <TableCell>
                                                                            <Link
                                                                                href={route('work-list.index', { site_id: item.site_id, search: item.plate_number })}
                                                                                className="text-sm font-semibold text-primary hover:underline"
                                                                            >
                                                                                Buka di Daftar Kerja
                                                                            </Link>
                                                                        </TableCell>
                                                                    </TableRow>
                                                                ))}
                                                            </TableBody>
                                                        </Table>
                                                    </div>
                                                </div>
                                            </div>
                                        </>
                                    )}
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
