import DangerButton from '@/Components/DangerButton';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, Site, Unit, VehicleCategoryOption } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';

type UnitFilters = {
    search: string;
    site_id: string;
    status: string;
    vehicle_category: string;
};

const STATUS_OPTIONS = [
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Tidak Aktif' },
    { value: 'breakdown', label: 'Breakdown' },
];

// Select tidak menerima nilai kosong, jadi "semua" dipakai sebagai sentinel.
const toSelectValue = (value: string): string => value || 'all';
const fromSelectValue = (value: string): string => (value === 'all' ? '' : value);

export default function Index({ auth, units, totalUnits, sites, vehicleCategories, filters }: PageProps<{ units: PaginatedCollection<Unit>; totalUnits: number; sites: Site[]; vehicleCategories: VehicleCategoryOption[]; filters: UnitFilters }>) {
    const canManage = auth.user.role === 'superadmin' || auth.user.role === 'spv_ho';
    const [search, setSearch] = useState(filters.search);
    const [siteId, setSiteId] = useState(filters.site_id);
    const [status, setStatus] = useState(filters.status);
    const [vehicleCategory, setVehicleCategory] = useState(filters.vehicle_category);
    const requestedSearch = useRef(filters.search);
    const hasActiveFilter = Boolean(filters.search || filters.site_id || filters.status || filters.vehicle_category);

    const query = (overrides: Partial<UnitFilters> = {}) => ({
        search: search || undefined,
        site_id: siteId || undefined,
        status: status || undefined,
        vehicle_category: vehicleCategory || undefined,
        ...overrides,
    });

    const apply = (overrides: Partial<UnitFilters> = {}) => {
        requestedSearch.current = search;
        router.get(route('units.index'), query(overrides), { preserveState: true, preserveScroll: true, replace: true });
    };

    // Ketikan pencarian ditunda supaya tiap huruf tidak memicu request.
    useEffect(() => {
        if (search === requestedSearch.current) {
            return;
        }

        const timer = window.setTimeout(() => apply(), 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        apply();
    };

    const reset = () => {
        setSearch('');
        setSiteId('');
        setStatus('');
        setVehicleCategory('');
        requestedSearch.current = '';
        router.get(route('units.index'), {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    const destroy = (unit: Unit) => {
        if (confirm(`Hapus unit ${unit.current_plate}?`)) {
            router.delete(route('units.destroy', unit.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Unit</h2>}>
            <Head title="Unit" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    {canManage && (
                        <Link href={route('units.create')}>
                            <PrimaryButton>Tambah Unit</PrimaryButton>
                        </Link>
                    )}

                    <Card className="bg-card text-card-foreground">
                        <CardContent>
                            <form onSubmit={submit} className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                                <div className="lg:col-span-2">
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground" htmlFor="unit-search">Cari</label>
                                    <TextInput
                                        id="unit-search"
                                        className="w-full"
                                        value={search}
                                        placeholder="Plat nomor, customer, merk, atau tipe"
                                        onChange={(event) => setSearch(event.target.value)}
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground">Lokasi</label>
                                    <Select value={toSelectValue(siteId)} onValueChange={(value) => { const next = fromSelectValue(value); setSiteId(next); apply({ site_id: next || undefined }); }}>
                                        <SelectTrigger className="w-full"><SelectValue placeholder="Semua lokasi" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Semua lokasi</SelectItem>
                                            {sites.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground">Status</label>
                                    <Select value={toSelectValue(status)} onValueChange={(value) => { const next = fromSelectValue(value); setStatus(next); apply({ status: next || undefined }); }}>
                                        <SelectTrigger className="w-full"><SelectValue placeholder="Semua status" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Semua status</SelectItem>
                                            {STATUS_OPTIONS.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-medium text-muted-foreground">Kategori Kendaraan</label>
                                    <Select value={toSelectValue(vehicleCategory)} onValueChange={(value) => { const next = fromSelectValue(value); setVehicleCategory(next); apply({ vehicle_category: next || undefined }); }}>
                                        <SelectTrigger className="w-full"><SelectValue placeholder="Semua kategori" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Semua kategori</SelectItem>
                                            {vehicleCategories.map((category) => <SelectItem key={category.value} value={category.value}>{category.label}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="flex items-end gap-2 lg:col-span-3">
                                    <PrimaryButton>Cari</PrimaryButton>
                                    {hasActiveFilter && <SecondaryButton type="button" onClick={reset}>Reset filter</SecondaryButton>}
                                </div>
                            </form>
                        </CardContent>
                        <CardHeader>
                            <CardTitle>Daftar Unit</CardTitle>
                            <CardDescription>
                                {hasActiveFilter
                                    ? `Menampilkan ${units.meta.total.toLocaleString('id-ID')} dari ${totalUnits.toLocaleString('id-ID')} unit`
                                    : `Total ${totalUnits.toLocaleString('id-ID')} unit`}
                            </CardDescription>
                        </CardHeader>
                        <CardContent><div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {['Plat Nomor', 'Lokasi', 'Customer', 'Tipe', 'Merk', 'Tahun', 'ODO', 'Status', 'Riwayat Plat', 'Aksi'].map((head) => (
                                        <TableHead key={head}>
                                            {head}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {units.data.map((unit) => (
                                    <TableRow key={unit.id}>
                                        <TableCell className="font-medium text-foreground">
                                            {unit.current_plate}
                                            <div className="mt-1 flex gap-1">
                                                {unit.is_warranty && <StatusBadge tone="warranty">Warranty</StatusBadge>}
                                                {unit.status === 'breakdown' && <StatusBadge tone="danger">Breakdown</StatusBadge>}
                                                {unit.needs_document_verification && <StatusBadge tone="warning">Perlu Verifikasi Dokumen</StatusBadge>}
                                            </div>
                                        </TableCell>
                                        <TableCell>{unit.site?.name}</TableCell>
                                        <TableCell>{unit.customer}</TableCell>
                                        <TableCell>{unit.type}</TableCell>
                                        <TableCell>{unit.brand}</TableCell>
                                        <TableCell>{unit.year}</TableCell>
                                        <TableCell>{unit.current_odo.toLocaleString('id-ID')}</TableCell>
                                        <TableCell>{{ active: 'Aktif', inactive: 'Tidak Aktif', breakdown: 'Breakdown' }[unit.status] ?? unit.status}</TableCell>
                                        <TableCell>
                                            <Link className="text-sm font-medium text-primary hover:underline" href={route('units.history', unit.id)}>
                                                Show History
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {canManage && (
                                                <div className="flex flex-wrap gap-2">
                                                    <Link className="text-sm font-medium text-primary hover:underline" href={route('units.edit', unit.id)}>
                                                        Edit
                                                    </Link>
                                                    <DangerButton onClick={() => destroy(unit)}>Hapus</DangerButton>
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {units.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={10} className="py-8 text-center text-muted-foreground">
                                            Tidak ada unit yang cocok dengan filter ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div><PaginationLinks meta={units.meta} /></CardContent></Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
