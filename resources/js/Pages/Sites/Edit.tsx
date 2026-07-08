import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ auth, site }: PageProps<{ site: Site }>) {
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Edit Site</h2>}><Head title="Edit Site" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form site={site} /></div></div></AuthenticatedLayout>;
}
