import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { PlanningItem } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Form({ planningItem }: { planningItem?: PlanningItem }) {
    const form = useForm({ name: planningItem?.name ?? '', interval_km: planningItem?.interval_km ?? 0, interval_days: planningItem?.interval_days ?? 0 });
    const submit = (event: FormEvent) => { event.preventDefault(); planningItem ? form.patch(route('planning-items.update', planningItem.id)) : form.post(route('planning-items.store')); };
    return <Card><CardContent><form onSubmit={submit} className="space-y-6"><div className="space-y-2"><InputLabel htmlFor="name" value="Nama" /><TextInput id="name" className="block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /><InputError message={form.errors.name} /></div><div className="grid gap-6 sm:grid-cols-2"><div className="space-y-2"><InputLabel htmlFor="interval_km" value="Interval KM" /><TextInput id="interval_km" type="number" min="0" className="block w-full" value={form.data.interval_km} onChange={(e) => form.setData('interval_km', Number(e.target.value))} required /><InputError message={form.errors.interval_km} /></div><div className="space-y-2"><InputLabel htmlFor="interval_days" value="Interval Hari" /><TextInput id="interval_days" type="number" min="0" className="block w-full" value={form.data.interval_days} onChange={(e) => form.setData('interval_days', Number(e.target.value))} required /><InputError message={form.errors.interval_days} /></div></div><div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Simpan</PrimaryButton><Link href={route('planning-items.index')} className="text-sm text-muted-foreground hover:text-foreground">Batal</Link></div></form></CardContent></Card>;
}
