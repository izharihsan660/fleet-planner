import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Region } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Form({ region }: { region?: Region }) {
    const form = useForm({ name: region?.name ?? '' });
    const submit = (event: FormEvent) => { event.preventDefault(); region ? form.patch(route('regions.update', region.id)) : form.post(route('regions.store')); };

    return <Card><CardContent><form onSubmit={submit} className="space-y-6">
        <div className="space-y-2"><InputLabel htmlFor="name" value="Nama" /><TextInput id="name" className="block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /><InputError message={form.errors.name} /></div>
        <div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Simpan</PrimaryButton><Link href={route('regions.index')} className="text-sm text-muted-foreground hover:text-foreground">Batal</Link></div>
    </form></CardContent></Card>;
}
