import DangerButton from '@/Components/DangerButton';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, Site } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, sites }: PageProps<{ sites: PaginatedCollection<Site & { units_count: number; users_count: number }> }>) {
    const canManage = auth.user.role === 'superadmin' || auth.user.role === 'spv_ho';

    const destroy = (site: Site) => {
        if (confirm(`Delete site ${site.name}?`)) {
            router.delete(route('sites.destroy', site.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Sites</h2>}>
            <Head title="Sites" />
            <div className="py-10"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                {canManage && <Link href={route('sites.create')}><PrimaryButton>Add Site</PrimaryButton></Link>}
                <Card><CardContent><div className="overflow-x-auto"><Table>
                    <TableHeader><TableRow>{['Name','Region','Units','Users','Actions'].map((head) => <TableHead key={head}>{head}</TableHead>)}</TableRow></TableHeader>
                    <TableBody>{sites.data.map((site) => <TableRow key={site.id}>
                        <TableCell className="font-medium text-foreground">{site.name}</TableCell><TableCell>{site.region}</TableCell><TableCell>{site.units_count}</TableCell><TableCell>{site.users_count}</TableCell>
                        <TableCell><div className="flex flex-wrap gap-2">{canManage && <><Link className="text-sm font-medium text-primary hover:underline" href={route('sites.edit', site.id)}>Edit</Link><DangerButton onClick={() => destroy(site)}>Delete</DangerButton></>}</div></TableCell>
                    </TableRow>)}</TableBody>
                </Table></div><PaginationLinks meta={sites.meta} /></CardContent></Card>
            </div></div>
        </AuthenticatedLayout>
    );
}
