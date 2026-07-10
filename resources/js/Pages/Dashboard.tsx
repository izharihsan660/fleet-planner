import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useTheme } from '@/Contexts/ThemeContext';
import { PageProps } from '@/types';
import { chartTheme } from '@/lib/chartTheme';
import { Head, Link, router } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type PlannerStatusCounts = {
    on_hold: number;
    waiting_approval: number;
    in_progress: number;
    complete_this_month: number;
    overdue: number;
};

type PlannerDashboard = {
    total_units: number;
    can_filter_region: boolean;
    selected_region_id: number | null;
    region_options: Array<{ id: number; name: string }>;
    status_counts: PlannerStatusCounts;
    status_chart: Array<{ key: keyof PlannerStatusCounts; label: string; value: number; color: string }>;
    site_rows: Array<{ site_id: number; site_name: string; unit_count: number; overdue_count: number }>;
    overdue_by_site_chart: Array<{ site_name: string; overdue_count: number }>;
};

type DashboardProps = PageProps<{
    overdueBanner: {
        count: number;
        threshold: number;
    };
    plannerDashboard: PlannerDashboard | null;
}>;

const statusLabels: Record<keyof PlannerStatusCounts, string> = {
    on_hold: 'On Hold',
    waiting_approval: 'Menunggu Approval',
    in_progress: 'In Progress',
    complete_this_month: 'Complete Bulan Ini',
    overdue: 'Overdue',
};

export default function Dashboard({ auth, overdueBanner, plannerDashboard }: DashboardProps) {
    const canSeeOverdueBanner = ['superadmin', 'spv_ho'].includes(auth.user.role) && overdueBanner.count > overdueBanner.threshold;
    const menuSuggestions = {
        superadmin: 'Gunakan menu di sisi kiri untuk membuka Work Orders, Daftar Kerja, Antrian Approval, Laporan, Master Data, dan Manajemen Pengguna.',
        spv_ho: 'Gunakan menu di sisi kiri untuk membuka Antrian Approval, Work Orders, Pemakaian Tinggi, Proyeksi, Laporan, dan Master Data.',
        planner_area: 'Gunakan menu di sisi kiri untuk membuka Work Orders, Daftar Kerja, Input KM, Riwayat Inspeksi, Pemakaian Tinggi, Proyeksi, dan Laporan.',
        mekanik: 'Kamu akan diarahkan ke Tugas Saya untuk melihat pekerjaan yang perlu diselesaikan.',
    }[auth.user.role] ?? 'Gunakan menu di sisi kiri untuk membuka halaman yang tersedia untuk akun kamu.';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-foreground">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-10">
                <div className="mx-auto grid max-w-7xl gap-5 px-4 sm:px-6 lg:grid-cols-3 lg:px-8">
                    {canSeeOverdueBanner && (
                        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900 shadow-xs dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-100 lg:col-span-3">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-sm font-semibold uppercase tracking-wide text-red-700 dark:text-red-200">Perhatian Overdue</p>
                                    <p className="mt-1 text-base font-semibold">{overdueBanner.count.toLocaleString('id-ID')} item maintenance overdue memerlukan tindakan.</p>
                                    <p className="mt-1 text-sm text-red-700 dark:text-red-200">Banner ini otomatis tampil selama jumlah overdue masih di atas {overdueBanner.threshold.toLocaleString('id-ID')} item.</p>
                                </div>
                                <Link href={`${route('reports.index')}?tab=overdue`} className="inline-flex items-center justify-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                                    Lihat Semua
                                </Link>
                            </div>
                        </div>
                    )}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Fleet Maintenance Planner</CardTitle>
                            <CardDescription>Ringkasan operasional maintenance harian.</CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Kamu sudah masuk. {menuSuggestions}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status Sistem</CardTitle>
                            <CardDescription>Workspace siap digunakan.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-xl bg-muted p-4 text-sm font-medium text-foreground">Aktif</div>
                        </CardContent>
                    </Card>

                    {plannerDashboard && <PlannerDashboardSummary dashboard={plannerDashboard} />}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function PlannerDashboardSummary({ dashboard }: { dashboard: PlannerDashboard }) {
    const { appliedTheme } = useTheme();
    const chart = chartTheme(appliedTheme);
    const statusEntries = Object.entries(dashboard.status_counts) as Array<[keyof PlannerStatusCounts, number]>;
    const hasOverdue = dashboard.status_counts.overdue > 0;
    const scopeLabel = dashboard.can_filter_region
        ? (dashboard.selected_region_id ? 'Data dibatasi ke region yang dipilih.' : 'Data gabungan semua region.')
        : 'Data dibatasi ke site dalam region kamu.';

    const handleRegionChange = (value: string) => {
        router.get(route('dashboard'), value === 'all' ? {} : { region_id: value }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <>
            <div className="lg:col-span-3">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-foreground">Ringkasan Maintenance</h3>
                        <p className="text-sm text-muted-foreground">{scopeLabel}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {dashboard.can_filter_region && (
                            <Select value={dashboard.selected_region_id ? String(dashboard.selected_region_id) : 'all'} onValueChange={handleRegionChange}>
                                <SelectTrigger className="w-full sm:w-48">
                                    <SelectValue placeholder="Pilih region" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua Region</SelectItem>
                                    {dashboard.region_options.map((region) => (
                                        <SelectItem key={region.id} value={String(region.id)}>{region.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <Button asChild><Link href={route('work-list.index')}>Daftar Kerja</Link></Button>
                        <Button asChild variant="outline"><Link href={route('work-orders.index')}>Work Orders</Link></Button>
                    </div>
                </div>
            </div>

            <Card>
                <CardContent>
                    <div className="text-sm text-muted-foreground">{dashboard.can_filter_region && !dashboard.selected_region_id ? 'Total Unit Semua Region' : 'Total Unit Region'}</div>
                    <div className="mt-2 text-3xl font-semibold text-foreground">{dashboard.total_units.toLocaleString('id-ID')}</div>
                </CardContent>
            </Card>
            {statusEntries.map(([key, value]) => (
                <Card key={key} className={key === 'overdue' && hasOverdue ? 'border-destructive/40 bg-destructive/5' : undefined}>
                    <CardContent>
                        <div className="flex items-center justify-between gap-2">
                            <div className="text-sm text-muted-foreground">{statusLabels[key]}</div>
                            {key === 'overdue' && hasOverdue && <Badge variant="destructive">Perlu tindakan</Badge>}
                        </div>
                        <div className={`mt-2 text-3xl font-semibold ${key === 'overdue' && hasOverdue ? 'text-destructive' : 'text-foreground'}`}>{value.toLocaleString('id-ID')}</div>
                    </CardContent>
                </Card>
            ))}

            <Card className="lg:col-span-1">
                <CardHeader>
                    <CardTitle>Proporsi Status WO Item</CardTitle>
                    <CardDescription>Chart melengkapi angka status di atas.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="h-72">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie data={dashboard.status_chart} dataKey="value" nameKey="label" innerRadius={58} outerRadius={92} paddingAngle={2}>
                                    {dashboard.status_chart.map((item) => <Cell key={item.key} fill={item.color} />)}
                                </Pie>
                                <Tooltip formatter={(value, name) => [Number(value).toLocaleString('id-ID'), name]} contentStyle={{ backgroundColor: chart.tooltipBackground, borderColor: chart.tooltipBorder, color: chart.tooltipText }} itemStyle={{ color: chart.tooltipText }} />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="mt-3 grid grid-cols-1 gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                        {dashboard.status_chart.map((item) => (
                            <div key={item.key} className="flex items-center gap-2">
                                <span className="size-3 rounded-full" style={{ backgroundColor: item.color }} />
                                <span>{item.label}: {item.value.toLocaleString('id-ID')}</span>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            <Card className="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Overdue per Site</CardTitle>
                    <CardDescription>Urut dari site dengan item overdue terbanyak.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="h-72">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={dashboard.overdue_by_site_chart} margin={{ top: 8, right: 8, left: -20, bottom: 8 }}>
                                <CartesianGrid stroke={chart.grid} vertical={false} />
                                <XAxis dataKey="site_name" tick={{ fontSize: 12, fill: chart.axis }} axisLine={{ stroke: chart.grid }} tickLine={{ stroke: chart.grid }} interval={0} />
                                <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: chart.axis }} axisLine={{ stroke: chart.grid }} tickLine={{ stroke: chart.grid }} />
                                <Tooltip formatter={(value) => [Number(value).toLocaleString('id-ID'), 'Overdue']} contentStyle={{ backgroundColor: chart.tooltipBackground, borderColor: chart.tooltipBorder, color: chart.tooltipText }} itemStyle={{ color: chart.tooltipText }} />
                                <Bar dataKey="overdue_count" fill="var(--destructive)" radius={[6, 6, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </CardContent>
            </Card>

            <Card className="lg:col-span-3">
                <CardHeader>
                    <CardTitle>Ringkasan per Site</CardTitle>
                    <CardDescription>Jumlah unit dan item overdue dalam scope dashboard ini.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Site</TableHead>
                                <TableHead>Jumlah Unit</TableHead>
                                <TableHead>Item Overdue</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {dashboard.site_rows.map((site) => (
                                <TableRow key={site.site_id}>
                                    <TableCell className="font-medium text-foreground">{site.site_name}</TableCell>
                                    <TableCell>{site.unit_count.toLocaleString('id-ID')}</TableCell>
                                    <TableCell className={site.overdue_count > 0 ? 'font-semibold text-destructive' : undefined}>{site.overdue_count.toLocaleString('id-ID')}</TableCell>
                                </TableRow>
                            ))}
                            {dashboard.site_rows.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={3} className="py-6 text-muted-foreground">Belum ada site dalam scope akun ini.</TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </>
    );
}
