import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Site } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Form({ site }: { site?: Site }) {
    const form = useForm({ name: site?.name ?? '', region: site?.region ?? '' });
    const submit = (event: FormEvent) => { event.preventDefault(); site ? form.patch(route('sites.update', site.id)) : form.post(route('sites.store')); };

    return <Card><CardContent><form onSubmit={submit} className="space-y-6">
        <div className="space-y-2"><InputLabel htmlFor="name" value="Name" /><TextInput id="name" className="block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /><InputError message={form.errors.name} /></div>
        <div className="space-y-2"><InputLabel htmlFor="region" value="Region" /><TextInput id="region" className="block w-full" value={form.data.region} onChange={(e) => form.setData('region', e.target.value)} required /><InputError message={form.errors.region} /></div>
        <div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Save</PrimaryButton><Link href={route('sites.index')} className="text-sm text-muted-foreground hover:text-foreground">Cancel</Link></div>
    </form></CardContent></Card>;
}
