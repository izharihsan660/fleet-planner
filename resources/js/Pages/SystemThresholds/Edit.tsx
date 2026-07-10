import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, SystemThreshold } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ auth, systemThreshold }: PageProps<{ systemThreshold: SystemThreshold }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Edit Pengaturan Sistem</h2>}><Head title="Edit Pengaturan Sistem" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form systemThreshold={systemThreshold} /></div></div></AuthenticatedLayout>; }
