import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site, Unit } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ auth, unit, sites }: PageProps<{ unit: Unit; sites: Site[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Edit Unit</h2>}><Head title="Edit Unit" /><div className="py-12"><div className="mx-auto max-w-4xl sm:px-6 lg:px-8"><Form unit={unit} sites={sites} /></div></div></AuthenticatedLayout>; }
