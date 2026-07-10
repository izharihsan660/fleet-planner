import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Region, Site, UserRole } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Create({ sites, regions, roles }: PageProps<{ sites: Site[]; regions: Region[]; roles: { value: UserRole; label: string }[] }>) {
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Tambah Pengguna</h2>}><Head title="Tambah Pengguna" /><div className="py-10"><div className="mx-auto max-w-4xl sm:px-6 lg:px-8"><Form sites={sites} regions={regions} roles={roles} /></div></div></AuthenticatedLayout>;
}
