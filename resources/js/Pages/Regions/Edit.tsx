import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Region } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ region }: PageProps<{ region: Region }>) {
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Edit Region</h2>}><Head title="Edit Region" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form region={region} /></div></div></AuthenticatedLayout>;
}
