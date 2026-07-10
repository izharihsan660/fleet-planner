import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PlanningItem, VehicleCategoryOption } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Create({ planningItems, vehicleCategories }: PageProps<{ planningItems: PlanningItem[]; vehicleCategories: VehicleCategoryOption[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Tambah Pengecualian Interval</h2>}><Head title="Tambah Pengecualian Interval" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form planningItems={planningItems} vehicleCategories={vehicleCategories} /></div></div></AuthenticatedLayout>; }
