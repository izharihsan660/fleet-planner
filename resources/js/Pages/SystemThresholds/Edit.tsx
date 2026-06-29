import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, SystemThreshold } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ auth, systemThreshold }: PageProps<{ systemThreshold: SystemThreshold }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Edit Threshold</h2>}><Head title="Edit Threshold" /><div className="py-12"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form systemThreshold={systemThreshold} /></div></div></AuthenticatedLayout>; }
