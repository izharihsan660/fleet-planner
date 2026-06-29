import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site, UserRole } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Create({ auth, sites, roles }: PageProps<{ sites: Site[]; roles: { value: UserRole; label: string }[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Create User</h2>}><Head title="Create User" /><div className="py-12"><div className="mx-auto max-w-4xl sm:px-6 lg:px-8"><Form sites={sites} roles={roles} /></div></div></AuthenticatedLayout>; }
