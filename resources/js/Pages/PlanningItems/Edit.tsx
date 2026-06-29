import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PlanningItem } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ auth, planningItem }: PageProps<{ planningItem: PlanningItem }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Edit Planning Item</h2>}><Head title="Edit Planning Item" /><div className="py-12"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form planningItem={planningItem} /></div></div></AuthenticatedLayout>; }
