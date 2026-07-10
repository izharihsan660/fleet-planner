import DangerButton from '@/Components/DangerButton';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, PlanningItemOverride } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ overrides }: PageProps<{ overrides: PaginatedCollection<PlanningItemOverride> }>) {
    const destroy = (override: PlanningItemOverride) => { if (confirm('Hapus pengecualian interval ini?')) router.delete(route('planning-item-overrides.destroy', override.id)); };
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Pengecualian Interval</h2>}><Head title="Pengecualian Interval" /><div className="py-10"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><Link href={route('planning-item-overrides.create')}><PrimaryButton>Tambah Pengecualian</PrimaryButton></Link><Card><CardContent><div className="overflow-x-auto"><Table><TableHeader><TableRow>{['Item','Kategori','Interval KM','Interval Hari','Aksi'].map((head) => <TableHead key={head}>{head}</TableHead>)}</TableRow></TableHeader><TableBody>{overrides.data.map((override) => <TableRow key={override.id}><TableCell>{override.planning_item?.name}</TableCell><TableCell>{override.vehicle_category}</TableCell><TableCell>{override.interval_km?.toLocaleString('id-ID') ?? '-'}</TableCell><TableCell>{override.interval_days?.toLocaleString('id-ID') ?? '-'}</TableCell><TableCell><div className="flex flex-wrap gap-2"><Link className="text-sm font-medium text-primary hover:underline" href={route('planning-item-overrides.edit', override.id)}>Edit</Link><DangerButton onClick={() => destroy(override)}>Hapus</DangerButton></div></TableCell></TableRow>)}</TableBody></Table></div><PaginationLinks meta={overrides.meta} /></CardContent></Card></div></div></AuthenticatedLayout>;
}
