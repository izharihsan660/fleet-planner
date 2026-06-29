import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Site, User, UserRole } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type RoleOption = { value: UserRole; label: string };

export default function Form({ managedUser, sites, roles }: { managedUser?: User; sites: Site[]; roles: RoleOption[] }) {
    const form = useForm({ name: managedUser?.name ?? '', email: managedUser?.email ?? '', password: '', role: managedUser?.role ?? 'admin_site' as UserRole, site_id: managedUser?.site_id ?? '' });
    const submit = (event: FormEvent) => { event.preventDefault(); managedUser ? form.patch(route('users.update', managedUser.id)) : form.post(route('users.store')); };
    return <form onSubmit={submit} className="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg"><div className="grid gap-6 sm:grid-cols-2"><div><InputLabel htmlFor="name" value="Name" /><TextInput id="name" className="mt-1 block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /><InputError message={form.errors.name} className="mt-2" /></div><div><InputLabel htmlFor="email" value="Email" /><TextInput id="email" type="email" className="mt-1 block w-full" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /><InputError message={form.errors.email} className="mt-2" /></div><div><InputLabel htmlFor="password" value={managedUser ? 'Password (blank to keep)' : 'Password'} /><TextInput id="password" type="password" className="mt-1 block w-full" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required={!managedUser} /><InputError message={form.errors.password} className="mt-2" /></div><div><InputLabel htmlFor="role" value="Role" /><select id="role" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value={form.data.role} onChange={(e) => form.setData('role', e.target.value as UserRole)} required>{roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}</select><InputError message={form.errors.role} className="mt-2" /></div><div><InputLabel htmlFor="site_id" value="Site" /><select id="site_id" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm" value={form.data.site_id} onChange={(e) => form.setData('site_id', e.target.value ? Number(e.target.value) : '')}><option value="">No site</option>{sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}</select><InputError message={form.errors.site_id} className="mt-2" /></div></div><div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Save</PrimaryButton><Link href={route('users.index')} className="text-sm text-gray-600 hover:text-gray-900">Cancel</Link></div></form>;
}
