import DangerButton from '@/Components/DangerButton';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import StatusBadge from '@/Components/StatusBadge';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, Unit } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, units }: PageProps<{ units: PaginatedCollection<Unit> }>) {
    const canManage = auth.user.role === 'superadmin' || auth.user.role === 'spv_ho';

    const destroy = (unit: Unit) => {
        if (confirm(`Delete unit ${unit.current_plate}?`)) {
            router.delete(route('units.destroy', unit.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Units</h2>}>
            <Head title="Units" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    {canManage && (
                        <Link href={route('units.create')}>
                            <PrimaryButton>Add Unit</PrimaryButton>
                        </Link>
                    )}

                    <Card><CardContent><div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {['Plate', 'Site', 'Customer', 'Type', 'Brand', 'Year', 'ODO', 'Status', 'Plate History', 'Actions'].map((head) => (
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
                                            </div>
                                        </TableCell>
                                        <TableCell>{unit.site?.name}</TableCell>
                                        <TableCell>{unit.customer}</TableCell>
                                        <TableCell>{unit.type}</TableCell>
                                        <TableCell>{unit.brand}</TableCell>
                                        <TableCell>{unit.year}</TableCell>
                                        <TableCell>{unit.current_odo.toLocaleString('id-ID')}</TableCell>
                                        <TableCell>{unit.status}</TableCell>
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
                                                    <DangerButton onClick={() => destroy(unit)}>Delete</DangerButton>
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div><PaginationLinks meta={units.meta} /></CardContent></Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
