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

type KmInputComparison = {
    current: number;
    previous: number;
    delta: number;
    percentage_change: number | null;
};

type KmInputTrend = {
    selected_days: number;
    period_start: string;
    period_end: string;
    period_label: string;
    total_count: number;
    series: Array<{
        date: string;
        short_label: string;
        full_label: string;
        count: number;
    }>;
    today_vs_yesterday: KmInputComparison;
    this_week_vs_last_week: KmInputComparison;
};

type PlannerDashboard = {
    total_units: number;
    km_input_today: {
        input_count: number;
        total_units: number;
        missing_count: number;
        percentage: number;
    };
    can_filter_region: boolean;
    selected_region_id: number | null;
    region_options: Array<{ id: number; name: string }>;
    status_counts: PlannerStatusCounts;
    status_chart: Array<{ key: keyof PlannerStatusCounts; label: string; value: number; color: string }>;
    site_rows: Array<{ site_id: number; site_name: string; unit_count: number; km_input_count: number; overdue_count: number }>;
    overdue_by_site_chart: Array<{ site_name: string; overdue_count: number }>;
    km_input_trend: KmInputTrend;
    km_trend_day_options: number[];
};

type DashboardProps = PageProps<{
    overdueBanner: {
        count: number;
        threshold: number;
    };
    plannerDashboard: PlannerDashboard | null;
}>;

const statusLabels: Record<keyof PlannerStatusCounts, string> = {
    on_hold: 'Menunggu',
    waiting_approval: 'Menunggu Approval',
    in_progress: 'Sedang Dikerjakan',
    complete_this_month: 'Selesai Bulan Ini',
    overdue: 'Terlambat',
};

export default function Dashboard({ auth, overdueBanner, plannerDashboard }: DashboardProps) {
    const canSeeOverdueBanner = ['superadmin', 'spv_ho'].includes(auth.user.role) && overdueBanner.count > overdueBanner.threshold;
    const menuSuggestions = {
        superadmin: 'Gunakan menu di sisi kiri untuk membuka Perintah Kerja (PK), Daftar Kerja, Antrian Approval, Laporan, Master Data, dan Manajemen Pengguna.',
        spv_ho: 'Gunakan menu di sisi kiri untuk membuka Antrian Approval, Perintah Kerja (PK), Pemakaian Tinggi, Proyeksi, Laporan, dan Master Data.',
        planner_area: 'Gunakan menu di sisi kiri untuk membuka Perintah Kerja (PK), Daftar Kerja, Input KM, Riwayat Inspeksi, Pemakaian Tinggi, Proyeksi, dan Laporan.',
        mekanik: 'Kamu akan diarahkan ke Tugas Saya untuk melihat pekerjaan yang perlu diselesaikan.',
    }[auth.user.role] ?? 'Gunakan menu di sisi kiri untuk membuka halaman yang tersedia untuk akun kamu.';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-foreground">
                    Ringkasan
                </h2>
            }
        >
            <Head title="Ringkasan" />

            <div className="py-10">
                <div className="mx-auto grid max-w-7xl gap-5 px-4 sm:px-6 lg:grid-cols-3 lg:px-8">
                    {canSeeOverdueBanner && (
                        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900 shadow-xs dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-100 lg:col-span-3">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-sm font-semibold uppercase tracking-wide text-red-700 dark:text-red-200">Perhatian: Terlambat</p>
                                    <p className="mt-1 text-base font-semibold">{overdueBanner.count.toLocaleString('id-ID')} item perawatan yang terlambat memerlukan tindakan.</p>
                                    <p className="mt-1 text-sm text-red-700 dark:text-red-200">Banner ini otomatis tampil selama jumlah item perawatan yang terlambat masih di atas {overdueBanner.threshold.toLocaleString('id-ID')} item.</p>
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
                            <CardDescription>Ringkasan operasional perawatan harian.</CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Kamu sudah masuk. {menuSuggestions}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status Sistem</CardTitle>
                            <CardDescription>Ruang Kerja siap digunakan.</CardDescription>
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
        ? (dashboard.selected_region_id ? 'Data dibatasi ke wilayah yang dipilih.' : 'Data gabungan semua wilayah.')
        : 'Data dibatasi ke lokasi dalam wilayah kamu.';

    const handleRegionChange = (value: string) => {
        router.get(route('dashboard'), {
            ...(value === 'all' ? {} : { region_id: value }),
            km_trend_days: dashboard.km_input_trend.selected_days,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const handleKmTrendDaysChange = (value: string) => {
        router.get(route('dashboard'), {
            ...(dashboard.selected_region_id ? { region_id: dashboard.selected_region_id } : {}),
            km_trend_days: Number(value),
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    return (
        <>
            <div className="lg:col-span-3">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-foreground">Ringkasan Perawatan</h3>
                        <p className="text-sm text-muted-foreground">{scopeLabel}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {dashboard.can_filter_region && (
                            <Select value={dashboard.selected_region_id ? String(dashboard.selected_region_id) : 'all'} onValueChange={handleRegionChange}>
                                <SelectTrigger className="w-full sm:w-48">
                                    <SelectValue placeholder="Pilih wilayah" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua Wilayah</SelectItem>
                                    {dashboard.region_options.map((region) => (
                                        <SelectItem key={region.id} value={String(region.id)}>{region.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <Button asChild><Link href={route('work-list.index')}>Daftar Kerja</Link></Button>
                        <Button asChild variant="outline"><Link href={route('work-orders.index')}>Perintah Kerja (PK)</Link></Button>
                    </div>
                </div>
            </div>

            <Card>
                <CardContent>
                    <div className="text-sm text-muted-foreground">{dashboard.can_filter_region && !dashboard.selected_region_id ? 'Total Unit Semua Wilayah' : 'Total Unit Wilayah'}</div>
                    <div className="mt-2 text-3xl font-semibold text-foreground">{dashboard.total_units.toLocaleString('id-ID')}</div>
                </CardContent>
            </Card>
            <Card className={dashboard.km_input_today.missing_count > 0 ? 'border-amber-300/60 bg-amber-50/60 dark:border-amber-500/40 dark:bg-amber-500/10' : undefined}>
                <CardContent>
                    <div className="flex items-center justify-between gap-2">
                        <div className="text-sm text-muted-foreground">Input KM Hari Ini</div>
                        {dashboard.km_input_today.missing_count > 0 && (
                            <Badge variant="outline" className="border-amber-400 text-amber-700 dark:border-amber-500/60 dark:text-amber-300">
                                {dashboard.km_input_today.missing_count.toLocaleString('id-ID')} unit belum
                            </Badge>
                        )}
                    </div>
                    <div className="mt-2 text-3xl font-semibold text-foreground">{dashboard.km_input_today.percentage}%</div>
                    <p className="mt-1 text-xs text-muted-foreground">{dashboard.km_input_today.input_count.toLocaleString('id-ID')} dari {dashboard.km_input_today.total_units.toLocaleString('id-ID')} unit sudah diinput mekanik.</p>
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

            <Card className="lg:col-span-3">
                <CardHeader>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <CardTitle>Tren Harian Input KM</CardTitle>
                            <CardDescription>
                                Jumlah input KM per hari untuk {dashboard.km_input_trend.period_label.toLowerCase()}.
                            </CardDescription>
                        </div>
                        <div className="w-full space-y-2 sm:w-52">
                            <label htmlFor="km-trend-days" className="text-sm font-medium text-foreground">Rentang data</label>
                            <Select value={String(dashboard.km_input_trend.selected_days)} onValueChange={handleKmTrendDaysChange}>
                                <SelectTrigger id="km-trend-days">
                                    <SelectValue placeholder="Pilih rentang" />
                                </SelectTrigger>
                                <SelectContent>
                                    {dashboard.km_trend_day_options.map((days) => (
                                        <SelectItem key={days} value={String(days)}>{days} hari terakhir</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="space-y-6">
                    <div className="grid gap-4 md:grid-cols-2">
                        <KmInputComparisonCard
                            title="Hari Ini vs Kemarin"
                            description="Perbandingan jumlah input harian."
                            currentLabel="Hari ini"
                            previousLabel="Kemarin"
                            comparison={dashboard.km_input_trend.today_vs_yesterday}
                        />
                        <KmInputComparisonCard
                            title="Minggu Ini vs Minggu Lalu"
                            description="Total 7 hari berjalan dibanding 7 hari sebelumnya."
                            currentLabel="7 hari berjalan"
                            previousLabel="7 hari sebelumnya"
                            comparison={dashboard.km_input_trend.this_week_vs_last_week}
                        />
                    </div>

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                        <div className="min-w-0 rounded-xl border bg-background p-4">
                            {dashboard.km_input_trend.total_count === 0 ? (
                                <div className="flex h-72 items-center justify-center px-6 text-center text-sm text-muted-foreground">
                                    Belum ada input KM pada rentang tanggal yang dipilih.
                                </div>
                            ) : (
                                <div className="h-72">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={dashboard.km_input_trend.series} margin={{ top: 8, right: 8, left: -20, bottom: 8 }}>
                                            <CartesianGrid stroke={chart.grid} vertical={false} />
                                            <XAxis
                                                dataKey="short_label"
                                                tick={{ fontSize: 12, fill: chart.axis }}
                                                axisLine={{ stroke: chart.grid }}
                                                tickLine={{ stroke: chart.grid }}
                                                interval={dashboard.km_input_trend.selected_days > 14 ? 4 : 0}
                                            />
                                            <YAxis
                                                allowDecimals={false}
                                                tick={{ fontSize: 12, fill: chart.axis }}
                                                axisLine={{ stroke: chart.grid }}
                                                tickLine={{ stroke: chart.grid }}
                                            />
                                            <Tooltip
                                                formatter={(value) => [Number(value).toLocaleString('id-ID'), 'Jumlah Input KM']}
                                                labelFormatter={(_, payload) => payload[0]?.payload?.full_label ?? ''}
                                                contentStyle={{ backgroundColor: chart.tooltipBackground, borderColor: chart.tooltipBorder, color: chart.tooltipText }}
                                                itemStyle={{ color: chart.tooltipText }}
                                            />
                                            <Bar dataKey="count" name="Jumlah Input KM" fill="var(--primary)" radius={[6, 6, 0, 0]} />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            )}
                        </div>

                        <div className="max-h-80 overflow-auto rounded-xl border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tanggal</TableHead>
                                        <TableHead className="text-right">Jumlah Input KM</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {[...dashboard.km_input_trend.series].reverse().map((day) => (
                                        <TableRow key={day.date}>
                                            <TableCell className="font-medium text-foreground">{day.full_label}</TableCell>
                                            <TableCell className="text-right tabular-nums">{day.count.toLocaleString('id-ID')}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card className="lg:col-span-1">
                <CardHeader>
                    <CardTitle>Proporsi Status Item Perintah Kerja (PK)</CardTitle>
                    <CardDescription>Grafik melengkapi angka status di atas.</CardDescription>
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
                    <CardTitle>Terlambat per Lokasi</CardTitle>
                    <CardDescription>Diurutkan dari lokasi dengan item terlambat terbanyak.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="h-72">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={dashboard.overdue_by_site_chart} margin={{ top: 8, right: 8, left: -20, bottom: 8 }}>
                                <CartesianGrid stroke={chart.grid} vertical={false} />
                                <XAxis dataKey="site_name" tick={{ fontSize: 12, fill: chart.axis }} axisLine={{ stroke: chart.grid }} tickLine={{ stroke: chart.grid }} interval={0} />
                                <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: chart.axis }} axisLine={{ stroke: chart.grid }} tickLine={{ stroke: chart.grid }} />
                                <Tooltip formatter={(value) => [Number(value).toLocaleString('id-ID'), 'Terlambat']} contentStyle={{ backgroundColor: chart.tooltipBackground, borderColor: chart.tooltipBorder, color: chart.tooltipText }} itemStyle={{ color: chart.tooltipText }} />
                                <Bar dataKey="overdue_count" fill="var(--destructive)" radius={[6, 6, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </CardContent>
            </Card>

            <Card className="lg:col-span-3">
                <CardHeader>
                    <CardTitle>Ringkasan per Lokasi</CardTitle>
                    <CardDescription>Jumlah unit dan item terlambat dalam cakupan ringkasan ini.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Lokasi</TableHead>
                                <TableHead>Jumlah Unit</TableHead>
                                <TableHead>Input KM Hari Ini</TableHead>
                                <TableHead>Item Terlambat</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {dashboard.site_rows.map((site) => (
                                <TableRow key={site.site_id}>
                                    <TableCell className="font-medium text-foreground">{site.site_name}</TableCell>
                                    <TableCell>{site.unit_count.toLocaleString('id-ID')}</TableCell>
                                    <TableCell className={site.unit_count > 0 && site.km_input_count < site.unit_count ? 'font-medium text-amber-700 dark:text-amber-300' : undefined}>
                                        {site.km_input_count.toLocaleString('id-ID')}/{site.unit_count.toLocaleString('id-ID')}
                                    </TableCell>
                                    <TableCell className={site.overdue_count > 0 ? 'font-semibold text-destructive' : undefined}>{site.overdue_count.toLocaleString('id-ID')}</TableCell>
                                </TableRow>
                            ))}
                            {dashboard.site_rows.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={4} className="py-6 text-muted-foreground">Belum ada lokasi dalam cakupan akun ini.</TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </>
    );
}

function KmInputComparisonCard({
    title,
    description,
    currentLabel,
    previousLabel,
    comparison,
}: {
    title: string;
    description: string;
    currentLabel: string;
    previousLabel: string;
    comparison: KmInputComparison;
}) {
    const isIncrease = comparison.delta > 0;
    const isDecrease = comparison.delta < 0;
    const changeLabel = isIncrease
        ? `Naik ${comparison.delta.toLocaleString('id-ID')} input`
        : isDecrease
            ? `Turun ${Math.abs(comparison.delta).toLocaleString('id-ID')} input`
            : 'Tetap, selisih 0 input';
    const percentageLabel = comparison.percentage_change === null
        ? (comparison.previous === 0 && comparison.current > 0 ? 'Persentase belum relevan karena nilai pembanding 0.' : null)
        : `Perubahan ${Math.abs(comparison.percentage_change).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`;

    return (
        <div className="rounded-xl border bg-muted/20 p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h4 className="font-semibold text-foreground">{title}</h4>
                    <p className="mt-1 text-xs text-muted-foreground">{description}</p>
                </div>
                <Badge
                    variant="outline"
                    className={isIncrease
                        ? 'border-emerald-400 text-emerald-700 dark:border-emerald-500/60 dark:text-emerald-300'
                        : isDecrease
                            ? 'border-red-400 text-red-700 dark:border-red-500/60 dark:text-red-300'
                            : undefined}
                >
                    {changeLabel}
                </Badge>
            </div>
            <div className="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <div className="text-xs text-muted-foreground">{currentLabel}</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-foreground">{comparison.current.toLocaleString('id-ID')}</div>
                </div>
                <div>
                    <div className="text-xs text-muted-foreground">{previousLabel}</div>
                    <div className="mt-1 text-2xl font-semibold tabular-nums text-foreground">{comparison.previous.toLocaleString('id-ID')}</div>
                </div>
            </div>
            {percentageLabel && <p className="mt-3 text-xs text-muted-foreground">{percentageLabel}</p>}
        </div>
    );
}
