import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { InspectionLog, PageProps, Unit } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ResourceCollection<T> = T[] | { data: T[] };

const collectionData = <T,>(collection: ResourceCollection<T>): T[] => (Array.isArray(collection) ? collection : collection.data);

export default function Index({ auth, inspectionLogs, units, filters }: PageProps<{ inspectionLogs: ResourceCollection<InspectionLog>; units: ResourceCollection<Unit>; filters: { unit_id?: string; inspection_date?: string } }>) {
    const unitData = collectionData(units);
    const inspectionLogData = collectionData(inspectionLogs);
    const canCreate = ['superadmin', 'admin_site', 'mekanik'].includes(auth.user.role);
    const [unitId, setUnitId] = useState(filters.unit_id ?? '');
    const [inspectionDate, setInspectionDate] = useState(filters.inspection_date ?? '');
    const filter = (event: FormEvent) => { event.preventDefault(); router.get(route('inspections.index'), { unit_id: unitId || undefined, inspection_date: inspectionDate || undefined }, { preserveState: true }); };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Log Inspeksi KM</h2>}><Head title="Log Inspeksi KM" /><div className="py-12"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><div className="flex items-center justify-between">{canCreate && <Link href={route('inspections.create')}><PrimaryButton>Input KM Harian</PrimaryButton></Link>}</div><form onSubmit={filter} className="grid gap-4 bg-white p-4 shadow-sm sm:rounded-lg md:grid-cols-3"><select className="rounded-md border-gray-300 shadow-sm" value={unitId} onChange={(event) => setUnitId(event.target.value)}><option value="">Semua Unit</option>{unitData.map((unit) => <option key={unit.id} value={unit.id}>{unit.current_plate}</option>)}</select><TextInput type="date" value={inspectionDate} onChange={(event) => setInspectionDate(event.target.value)} /><PrimaryButton>Filter</PrimaryButton></form><div className="overflow-hidden bg-white shadow-sm sm:rounded-lg"><table className="min-w-full divide-y divide-gray-200"><thead className="bg-gray-50"><tr>{['Tanggal','Unit','Odometer','Mekanik','Site'].map((head) => <th key={head} className="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">{head}</th>)}</tr></thead><tbody className="divide-y divide-gray-200 bg-white">{inspectionLogData.map((log) => <tr key={log.id}><td className="px-6 py-4 text-sm text-gray-900">{log.inspection_date}</td><td className="px-6 py-4 text-sm font-medium text-gray-900">{log.unit?.current_plate}</td><td className="px-6 py-4 text-sm text-gray-500">{log.odometer.toLocaleString()}</td><td className="px-6 py-4 text-sm text-gray-500">{log.mechanic?.name}</td><td className="px-6 py-4 text-sm text-gray-500">{log.unit?.site?.name}</td></tr>)}{inspectionLogData.length === 0 && <tr><td colSpan={5} className="px-6 py-4 text-center text-sm text-gray-500">Belum ada log inspeksi.</td></tr>}</tbody></table></div></div></div></AuthenticatedLayout>;
}
