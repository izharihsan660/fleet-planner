import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Unit } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, units }: PageProps<{ units: Unit[] }>) {
    const canManage = auth.user.role === 'superadmin' || auth.user.role === 'planner_ho';

    const destroy = (unit: Unit) => {
        if (confirm(`Delete unit ${unit.current_plate}?`)) {
            router.delete(route('units.destroy', unit.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Units</h2>}>
            <Head title="Units" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    {canManage && (
                        <Link href={route('units.create')}>
                            <PrimaryButton>Add Unit</PrimaryButton>
                        </Link>
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['Plate', 'Site', 'Customer', 'Type', 'Brand', 'Year', 'ODO', 'Status', 'Plate History', 'Actions'].map((head) => (
                                        <th key={head} className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">
                                            {head}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {units.map((unit) => (
                                    <tr key={unit.id}>
                                        <td className="px-6 py-4 text-sm font-medium text-gray-900">
                                            {unit.current_plate}
                                            <div className="mt-1 flex gap-1">
                                                {unit.is_warranty && <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">Warranty</span>}
                                                {unit.status === 'breakdown' && <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">Breakdown</span>}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{unit.site?.name}</td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{unit.customer}</td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{unit.type}</td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{unit.brand}</td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{unit.year}</td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{unit.current_odo.toLocaleString()}</td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{unit.status}</td>
                                        <td className="px-6 py-4 text-sm">
                                            <Link className="inline-flex items-center rounded-md border border-indigo-200 px-3 py-1.5 font-medium text-indigo-700 hover:bg-indigo-50" href={route('units.history', unit.id)}>
                                                Show History
                                            </Link>
                                        </td>
                                        <td className="space-x-3 px-6 py-4 text-sm">
                                            {canManage && (
                                                <>
                                                    <Link className="text-indigo-600 hover:text-indigo-900" href={route('units.edit', unit.id)}>
                                                        Edit
                                                    </Link>
                                                    <DangerButton onClick={() => destroy(unit)}>Delete</DangerButton>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
