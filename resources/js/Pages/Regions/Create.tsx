import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Create() {
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Create Region</h2>}><Head title="Create Region" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form /></div></div></AuthenticatedLayout>;
}
