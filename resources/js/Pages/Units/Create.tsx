import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Create({ auth, sites }: PageProps<{ sites: Site[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Create Unit</h2>}><Head title="Create Unit" /><div className="py-12"><div className="mx-auto max-w-4xl sm:px-6 lg:px-8"><Form sites={sites} /></div></div></AuthenticatedLayout>; }
