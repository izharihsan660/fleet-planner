import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, Region } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ regions }: PageProps<{ regions: PaginatedCollection<Region> }>) {
    const destroy = (region: Region) => { if (confirm(`Hapus region ${region.name}?`)) router.delete(route('regions.destroy', region.id)); };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Region</h2>}><Head title="Region" /><div className="py-10"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><Link href={route('regions.create')}><PrimaryButton>Tambah Region</PrimaryButton></Link><Card><CardContent><div className="overflow-x-auto"><Table><TableHeader><TableRow>{['Nama','Sites','Aksi'].map((head) => <TableHead key={head}>{head}</TableHead>)}</TableRow></TableHeader><TableBody>{regions.data.map((region) => <TableRow key={region.id}><TableCell className="font-medium text-foreground">{region.name}</TableCell><TableCell>{region.sites_count ?? 0}</TableCell><TableCell><div className="flex flex-wrap gap-2"><Link className="text-sm font-medium text-primary hover:underline" href={route('regions.edit', region.id)}>Edit</Link><Button variant="destructive" className="uppercase tracking-widest" onClick={() => destroy(region)}>Hapus</Button></div></TableCell></TableRow>)}</TableBody></Table></div><PaginationLinks meta={regions.meta} /></CardContent></Card></div></div></AuthenticatedLayout>;
}
