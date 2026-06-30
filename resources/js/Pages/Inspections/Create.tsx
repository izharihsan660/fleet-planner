import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Unit } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useMemo } from 'react';

type ResourceCollection<T> = T[] | { data: T[] };

const collectionData = <T,>(collection: ResourceCollection<T>): T[] => (Array.isArray(collection) ? collection : collection.data);

const digitsToInteger = (value: string): number => parseInt(value.replace(/\D/g, '') || '0', 10);

export default function Create({ units, today, minimumInspectionData }: PageProps<{ units: ResourceCollection<Unit>; today: string; minimumInspectionData: number }>) {
    const unitData = collectionData(units);
    const form = useForm({ unit_id: unitData[0]?.id ?? '', inspection_date: today, odometer: unitData[0]?.current_odo ?? 0 });
    const selectedUnit = useMemo(() => unitData.find((unit) => unit.id === Number(form.data.unit_id)), [form.data.unit_id, unitData]);
    const hasInsufficientData = selectedUnit ? (selectedUnit.inspection_logs_count ?? 0) < minimumInspectionData : false;
    const submit = (event: FormEvent) => { event.preventDefault(); form.post(route('inspections.store')); };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Input KM Harian</h2>}><Head title="Input KM Harian" /><div className="py-12"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><form onSubmit={submit} className="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg"><div><InputLabel htmlFor="unit_id" value="Unit" /><select id="unit_id" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value={form.data.unit_id} onChange={(event) => { const unit = unitData.find((item) => item.id === Number(event.target.value)); form.setData((data) => ({ ...data, unit_id: Number(event.target.value), odometer: unit?.current_odo ?? data.odometer })); }} required><option value="" disabled>Pilih unit</option>{unitData.map((unit) => <option key={unit.id} value={unit.id}>{unit.current_plate} - {unit.site?.name}</option>)}</select><InputError message={form.errors.unit_id} className="mt-2" /></div>{selectedUnit && <div className="rounded-md bg-gray-50 p-4 text-sm text-gray-700"><p>ODO saat ini: <span className="font-semibold">{selectedUnit.current_odo.toLocaleString()}</span></p><p>Rata-rata KM/hari: <span className="font-semibold">{selectedUnit.avg_km_per_day?.toLocaleString() ?? '-'}</span></p></div>}{hasInsufficientData && <div className="rounded-md border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800">Data inspeksi unit ini masih kurang dari threshold ({minimumInspectionData} data), rata-rata pemakaian belum akurat.</div>}<div className="grid gap-6 sm:grid-cols-2"><div><InputLabel htmlFor="inspection_date" value="Tanggal Inspeksi" /><TextInput id="inspection_date" type="date" max={today} className="mt-1 block w-full" value={form.data.inspection_date} onChange={(event) => form.setData('inspection_date', event.target.value)} required /><InputError message={form.errors.inspection_date} className="mt-2" /></div><div><InputLabel htmlFor="odometer" value="Odometer" /><TextInput id="odometer" type="text" inputMode="numeric" pattern="[0-9]*" min="0" className="mt-1 block w-full" value={String(form.data.odometer)} onChange={(event) => form.setData('odometer', digitsToInteger(event.target.value))} required /><InputError message={form.errors.odometer} className="mt-2" /></div></div><div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Simpan KM</PrimaryButton><Link href={route('inspections.index')} className="text-sm text-gray-600 hover:text-gray-900">Lihat Log</Link></div></form></div></div></AuthenticatedLayout>;
}
