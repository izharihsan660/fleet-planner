import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PlanningItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, planningItems }: PageProps<{ planningItems: PlanningItem[] }>) {
    const destroy = (item: PlanningItem) => { if (confirm(`Delete ${item.name}?`)) router.delete(route('planning-items.destroy', item.id)); };
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Planning Items</h2>}><Head title="Planning Items" /><div className="py-12"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><Link href={route('planning-items.create')}><PrimaryButton>Add Planning Item</PrimaryButton></Link><div className="overflow-hidden bg-white shadow-sm sm:rounded-lg"><table className="min-w-full divide-y divide-gray-200"><thead className="bg-gray-50"><tr>{['Name','Interval KM','Interval Days','Actions'].map((head) => <th key={head} className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{head}</th>)}</tr></thead><tbody className="divide-y divide-gray-200 bg-white">{planningItems.map((item) => <tr key={item.id}><td className="px-6 py-4 text-sm font-medium text-gray-900">{item.name}</td><td className="px-6 py-4 text-sm text-gray-500">{item.interval_km}</td><td className="px-6 py-4 text-sm text-gray-500">{item.interval_days}</td><td className="space-x-3 px-6 py-4 text-sm"><Link className="text-indigo-600 hover:text-indigo-900" href={route('planning-items.edit', item.id)}>Edit</Link><DangerButton onClick={() => destroy(item)}>Delete</DangerButton></td></tr>)}</tbody></table></div></div></div></AuthenticatedLayout>;
}
