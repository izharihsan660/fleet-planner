import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Site } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Form({ site }: { site?: Site }) {
    const form = useForm({ name: site?.name ?? '', region: site?.region ?? '' });
    const submit = (event: FormEvent) => { event.preventDefault(); site ? form.patch(route('sites.update', site.id)) : form.post(route('sites.store')); };

    return <form onSubmit={submit} className="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
        <div><InputLabel htmlFor="name" value="Name" /><TextInput id="name" className="mt-1 block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /><InputError message={form.errors.name} className="mt-2" /></div>
        <div><InputLabel htmlFor="region" value="Region" /><TextInput id="region" className="mt-1 block w-full" value={form.data.region} onChange={(e) => form.setData('region', e.target.value)} required /><InputError message={form.errors.region} className="mt-2" /></div>
        <div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Save</PrimaryButton><Link href={route('sites.index')} className="text-sm text-gray-600 hover:text-gray-900">Cancel</Link></div>
    </form>;
}
