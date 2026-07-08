import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
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

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Input KM Harian</h2>}><Head title="Input KM Harian" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Card><CardContent><form onSubmit={submit} className="space-y-6"><div className="space-y-2"><InputLabel htmlFor="unit_id" value="Unit" /><Select value={String(form.data.unit_id)} onValueChange={(value) => { const unit = unitData.find((item) => item.id === Number(value)); form.setData((data) => ({ ...data, unit_id: Number(value), odometer: unit?.current_odo ?? data.odometer })); }}><SelectTrigger id="unit_id"><SelectValue placeholder="Pilih unit" /></SelectTrigger><SelectContent>{unitData.map((unit) => <SelectItem key={unit.id} value={String(unit.id)}>{unit.current_plate} - {unit.site?.name}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.unit_id} /></div>{selectedUnit && <div className="rounded-xl bg-muted/50 p-4 text-sm text-muted-foreground"><p>ODO saat ini: <span className="font-semibold text-foreground">{selectedUnit.current_odo.toLocaleString('id-ID')}</span></p><p>Rata-rata KM/hari: <span className="font-semibold text-foreground">{selectedUnit.avg_km_per_day?.toLocaleString('id-ID') ?? '-'}</span></p></div>}{hasInsufficientData && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Data inspeksi unit ini masih kurang dari threshold ({minimumInspectionData} data), rata-rata pemakaian belum akurat.</div>}<div className="grid gap-6 sm:grid-cols-2"><div className="space-y-2"><InputLabel htmlFor="inspection_date" value="Tanggal Inspeksi" /><TextInput id="inspection_date" type="date" max={today} className="block w-full" value={form.data.inspection_date} onChange={(event) => form.setData('inspection_date', event.target.value)} required /><InputError message={form.errors.inspection_date} /></div><div className="space-y-2"><InputLabel htmlFor="odometer" value="Odometer" /><TextInput id="odometer" type="text" inputMode="numeric" pattern="[0-9]*" min="0" className="block w-full" value={String(form.data.odometer)} onChange={(event) => form.setData('odometer', digitsToInteger(event.target.value))} required /><InputError message={form.errors.odometer} /></div></div><div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Simpan KM</PrimaryButton><Link href={route('inspections.index')} className="text-sm text-muted-foreground hover:text-foreground">Lihat Log</Link></div></form></CardContent></Card></div></div></AuthenticatedLayout>;
}
