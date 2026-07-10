import DangerButton from '@/Components/DangerButton';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, SystemThreshold } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

const keyLabels: Record<string, string> = {
    warning_km: 'Batas KM Peringatan',
    warning_days: 'Batas Hari Peringatan',
    ancang_ancang_km: 'KM Ancang-ancang',
    ancang_ancang_days: 'Hari Ancang-ancang',
    upcoming_km: 'KM Mendatang',
    upcoming_days: 'Hari Mendatang',
    high_usage_threshold: 'Ambang Pemakaian Tinggi',
    min_inspection_data: 'Minimal Data Inspeksi',
    rolling_window_days: 'Jendela Rata-rata KM (Hari)',
};

const keyDescriptions: Record<string, string> = {
    warning_km: 'Berapa KM sebelum jatuh tempo, peringatan mulai muncul.',
    warning_days: 'Berapa hari sebelum jatuh tempo, peringatan mulai muncul.',
    ancang_ancang_km: 'Berapa KM sebelum jatuh tempo, pratinjau ancang-ancang ditampilkan.',
    ancang_ancang_days: 'Berapa hari sebelum jatuh tempo, pratinjau ancang-ancang ditampilkan.',
    upcoming_km: 'Berapa KM sebelum jatuh tempo, item tampil di pratinjau mendatang.',
    upcoming_days: 'Berapa hari sebelum jatuh tempo, item tampil di pratinjau mendatang.',
    high_usage_threshold: 'Persentase pemakaian KM yang dianggap tinggi (memicu flag High Usage).',
    min_inspection_data: 'Minimum jumlah log inspeksi yang diperlukan untuk menghitung proyeksi.',
    rolling_window_days: 'Berapa hari ke belakang dipakai untuk menghitung rata-rata pemakaian KM.',
};

export default function Index({ auth, systemThresholds }: PageProps<{ systemThresholds: PaginatedCollection<SystemThreshold> }>) {
    const destroy = (threshold: SystemThreshold) => {
        if (confirm(`Hapus pengaturan ${threshold.key}?`)) {
            router.delete(route('system-thresholds.destroy', threshold.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Pengaturan Sistem</h2>}>
            <Head title="Pengaturan Sistem" />
            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <Link href={route('system-thresholds.create')}><PrimaryButton>Tambah Pengaturan</PrimaryButton></Link>
                    <Card>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {['Kode Pengaturan', 'Nilai', 'Keterangan', 'Diubah Oleh', 'Aksi'].map((head) => (
                                                <TableHead key={head}>{head}</TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {systemThresholds.data.map((threshold) => (
                                            <TableRow key={threshold.id}>
                                                <TableCell className="font-medium text-foreground">
                                                    <span>{keyLabels[threshold.key] ?? threshold.key}</span>
                                                    <span className="ml-1 text-xs font-normal text-muted-foreground">({threshold.key})</span>
                                                </TableCell>
                                                <TableCell>{threshold.value}</TableCell>
                                                <TableCell className="max-w-xs text-sm text-muted-foreground">
                                                    {keyDescriptions[threshold.key] ?? threshold.description}
                                                </TableCell>
                                                <TableCell>{threshold.updated_by?.name ?? '-'}</TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-2">
                                                        <Link className="text-sm font-medium text-primary hover:underline" href={route('system-thresholds.edit', threshold.id)}>Edit</Link>
                                                        <DangerButton onClick={() => destroy(threshold)}>Hapus</DangerButton>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <PaginationLinks meta={systemThresholds.meta} />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
