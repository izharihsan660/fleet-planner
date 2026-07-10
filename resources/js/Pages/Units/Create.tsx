import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site, VehicleCategoryOption } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Create({ auth, sites, vehicleCategories }: PageProps<{ sites: Site[]; vehicleCategories: VehicleCategoryOption[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Tambah Unit</h2>}><Head title="Tambah Unit" /><div className="py-10"><div className="mx-auto max-w-4xl sm:px-6 lg:px-8"><Form sites={sites} vehicleCategories={vehicleCategories} /></div></div></AuthenticatedLayout>; }
