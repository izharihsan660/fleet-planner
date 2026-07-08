import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site, Unit, VehicleCategoryOption } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ auth, unit, sites, vehicleCategories }: PageProps<{ unit: Unit; sites: Site[]; vehicleCategories: VehicleCategoryOption[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Edit Unit</h2>}><Head title="Edit Unit" /><div className="py-10"><div className="mx-auto max-w-4xl sm:px-6 lg:px-8"><Form unit={unit} sites={sites} vehicleCategories={vehicleCategories} /></div></div></AuthenticatedLayout>; }
