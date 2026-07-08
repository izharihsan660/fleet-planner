import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { PlanningItem, PlanningItemOverride, VehicleCategory, VehicleCategoryOption } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

const toNumberOrNull = (value: string): number | null => value === '' ? null : parseInt(value.replace(/\D/g, '') || '0', 10);

export default function Form({ override, planningItems, vehicleCategories }: { override?: PlanningItemOverride; planningItems: PlanningItem[]; vehicleCategories: VehicleCategoryOption[] }) {
    const form = useForm({ planning_item_id: override?.planning_item_id ?? planningItems[0]?.id ?? '', vehicle_category: override?.vehicle_category ?? 'pickup_suv', interval_km: override?.interval_km ?? null, interval_days: override?.interval_days ?? null });
    const submit = (event: FormEvent) => { event.preventDefault(); override ? form.patch(route('planning-item-overrides.update', override.id)) : form.post(route('planning-item-overrides.store')); };

    return <Card><CardContent><form onSubmit={submit} className="space-y-6"><div className="grid gap-6 sm:grid-cols-2"><div className="space-y-2"><InputLabel value="Planning Item" /><Select value={String(form.data.planning_item_id)} onValueChange={(value) => form.setData('planning_item_id', Number(value))}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{planningItems.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.planning_item_id} /></div><div className="space-y-2"><InputLabel value="Kategori Kendaraan" /><Select value={form.data.vehicle_category} onValueChange={(value) => form.setData('vehicle_category', value as VehicleCategory)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{vehicleCategories.map((category) => <SelectItem key={category.value} value={category.value}>{category.label}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.vehicle_category} /></div><div className="space-y-2"><InputLabel value="Override KM" /><TextInput className="block w-full" value={form.data.interval_km ?? ''} onChange={(event) => form.setData('interval_km', toNumberOrNull(event.target.value))} /><InputError message={form.errors.interval_km} /></div><div className="space-y-2"><InputLabel value="Override Hari" /><TextInput className="block w-full" value={form.data.interval_days ?? ''} onChange={(event) => form.setData('interval_days', toNumberOrNull(event.target.value))} /><InputError message={form.errors.interval_days} /></div></div><div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Save</PrimaryButton><Link href={route('planning-item-overrides.index')} className="text-sm text-muted-foreground hover:text-foreground">Cancel</Link></div></form></CardContent></Card>;
}
