import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PlanningItem, PlanningItemOverride, VehicleCategoryOption } from '@/types';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ override, planningItems, vehicleCategories }: PageProps<{ override: PlanningItemOverride; planningItems: PlanningItem[]; vehicleCategories: VehicleCategoryOption[] }>) { return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Edit Interval Override</h2>}><Head title="Edit Interval Override" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Form override={override} planningItems={planningItems} vehicleCategories={vehicleCategories} /></div></div></AuthenticatedLayout>; }
