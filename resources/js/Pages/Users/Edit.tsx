import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site, User, UserRole } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ auth, managedUser, sites, roles }: PageProps<{ managedUser: User; sites: Site[]; roles: { value: UserRole; label: string }[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Edit User</h2>}><Head title="Edit User" /><div className="py-12"><div className="mx-auto max-w-4xl sm:px-6 lg:px-8"><Form managedUser={managedUser} sites={sites} roles={roles} /></div></div></AuthenticatedLayout>; }
