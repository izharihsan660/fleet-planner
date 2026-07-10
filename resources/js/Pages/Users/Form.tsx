import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Region, Site, User, UserRole } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type RoleOption = { value: UserRole; label: string };

type UserFormData = {
    name: string;
    email: string;
    password: string;
    role: UserRole;
    site_id: number | '';
    region_id: number | '';
};

export default function Form({ managedUser, sites, regions, roles }: { managedUser?: User; sites: Site[]; regions: Region[]; roles: RoleOption[] }) {
    const form = useForm<UserFormData>({
        name: managedUser?.name ?? '',
        email: managedUser?.email ?? '',
        password: '',
        role: managedUser?.role ?? 'planner_area',
        site_id: managedUser?.site_id ?? '',
        region_id: managedUser?.region_id ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        managedUser ? form.patch(route('users.update', managedUser.id)) : form.post(route('users.store'));
    };

    const updateRole = (role: UserRole) => {
        form.setData((data) => ({
            ...data,
            role,
            site_id: role === 'mekanik' ? data.site_id : '',
            region_id: role === 'planner_area' ? data.region_id : '',
        }));
    };

    return <Card><CardContent><form onSubmit={submit} className="space-y-6"><div className="grid gap-6 sm:grid-cols-2"><div className="space-y-2"><InputLabel htmlFor="name" value="Nama" /><TextInput id="name" className="block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /><InputError message={form.errors.name} /></div><div className="space-y-2"><InputLabel htmlFor="email" value="Email" /><TextInput id="email" type="email" className="block w-full" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /><InputError message={form.errors.email} /></div><div className="space-y-2"><InputLabel htmlFor="password" value={managedUser ? 'Kata Sandi (kosongkan untuk tidak mengubah)' : 'Kata Sandi'} /><TextInput id="password" type="password" className="block w-full" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required={!managedUser} /><InputError message={form.errors.password} /></div><div className="space-y-2"><InputLabel htmlFor="role" value="Peran" /><Select value={form.data.role} onValueChange={(value) => updateRole(value as UserRole)}><SelectTrigger id="role"><SelectValue /></SelectTrigger><SelectContent>{roles.map((role) => <SelectItem key={role.value} value={role.value}>{role.label}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.role} /></div>{form.data.role === 'planner_area' && <div className="space-y-2"><InputLabel htmlFor="region_id" value="Region" /><Select value={form.data.region_id ? String(form.data.region_id) : 'none'} onValueChange={(value) => form.setData('region_id', value === 'none' ? '' : Number(value))}><SelectTrigger id="region_id"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">No region</SelectItem>{regions.map((region) => <SelectItem key={region.id} value={String(region.id)}>{region.name}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.region_id} /></div>}{form.data.role === 'mekanik' && <div className="space-y-2"><InputLabel htmlFor="site_id" value="Site" /><Select value={form.data.site_id ? String(form.data.site_id) : 'none'} onValueChange={(value) => form.setData('site_id', value === 'none' ? '' : Number(value))}><SelectTrigger id="site_id"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">No site</SelectItem>{sites.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.site_id} /></div>}</div><div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Simpan</PrimaryButton><Link href={route('users.index')} className="text-sm text-muted-foreground hover:text-foreground">Batal</Link></div></form></CardContent></Card>;
}
