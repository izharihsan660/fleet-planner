import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Region, Site } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Form({ site, regions = [] }: { site?: Site; regions?: Region[] }) {
    const form = useForm({ name: site?.name ?? '', region: site?.region ?? '', region_id: site?.region_id ?? '' });
    const submit = (event: FormEvent) => { event.preventDefault(); site ? form.patch(route('sites.update', site.id)) : form.post(route('sites.store')); };

    return <Card><CardContent><form onSubmit={submit} className="space-y-6">
        <div className="space-y-2"><InputLabel htmlFor="name" value="Nama" /><TextInput id="name" className="block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /><InputError message={form.errors.name} /></div>
        <div className="space-y-2"><InputLabel htmlFor="region" value="Area/Provinsi" /><TextInput id="region" className="block w-full" value={form.data.region} onChange={(e) => form.setData('region', e.target.value)} required /><InputError message={form.errors.region} /></div>
        <div className="space-y-2"><InputLabel htmlFor="region_id" value="Planner Region" /><Select value={form.data.region_id ? String(form.data.region_id) : 'none'} onValueChange={(value) => form.setData('region_id', value === 'none' ? '' : Number(value))}><SelectTrigger id="region_id"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Tanpa Region</SelectItem>{regions.map((region) => <SelectItem key={region.id} value={String(region.id)}>{region.name}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.region_id} /></div>
        <div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Simpan</PrimaryButton><Link href={route('sites.index')} className="text-sm text-muted-foreground hover:text-foreground">Batal</Link></div>
    </form></CardContent></Card>;
}
