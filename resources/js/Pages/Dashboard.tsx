import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';

type DashboardProps = PageProps<{
    overdueBanner: {
        count: number;
        threshold: number;
    };
}>;

export default function Dashboard({ auth, overdueBanner }: DashboardProps) {
    const canSeeOverdueBanner = ['superadmin', 'spv_ho'].includes(auth.user.role) && overdueBanner.count > overdueBanner.threshold;

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
                        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900 shadow-xs lg:col-span-3">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-sm font-semibold uppercase tracking-wide text-red-700">Perhatian Overdue</p>
                                    <p className="mt-1 text-base font-semibold">{overdueBanner.count.toLocaleString('id-ID')} item maintenance overdue memerlukan tindakan.</p>
                                    <p className="mt-1 text-sm text-red-700">Banner ini otomatis tampil selama jumlah overdue masih di atas {overdueBanner.threshold.toLocaleString('id-ID')} item.</p>
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
                            You're logged in! Gunakan menu di sisi kiri untuk membuka Work Orders, High Usage, Projections, dan Reports.
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
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
