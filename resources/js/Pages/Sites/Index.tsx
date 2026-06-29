import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, sites }: PageProps<{ sites: (Site & { units_count: number; users_count: number })[] }>) {
    const canManage = auth.user.role === 'superadmin' || auth.user.role === 'planner_ho';

    const destroy = (site: Site) => {
        if (confirm(`Delete site ${site.name}?`)) {
            router.delete(route('sites.destroy', site.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Sites</h2>}>
            <Head title="Sites" />
            <div className="py-12"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                {canManage && <Link href={route('sites.create')}><PrimaryButton>Add Site</PrimaryButton></Link>}
                <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg"><table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50"><tr>{['Name','Region','Units','Users','Actions'].map((head) => <th key={head} className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{head}</th>)}</tr></thead>
                    <tbody className="divide-y divide-gray-200 bg-white">{sites.map((site) => <tr key={site.id}>
                        <td className="px-6 py-4 text-sm font-medium text-gray-900">{site.name}</td><td className="px-6 py-4 text-sm text-gray-500">{site.region}</td><td className="px-6 py-4 text-sm text-gray-500">{site.units_count}</td><td className="px-6 py-4 text-sm text-gray-500">{site.users_count}</td>
                        <td className="space-x-3 px-6 py-4 text-sm">{canManage && <><Link className="text-indigo-600 hover:text-indigo-900" href={route('sites.edit', site.id)}>Edit</Link><DangerButton onClick={() => destroy(site)}>Delete</DangerButton></>}</td>
                    </tr>)}</tbody>
                </table></div>
            </div></div>
        </AuthenticatedLayout>
    );
}
