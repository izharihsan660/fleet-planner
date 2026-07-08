import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Site, Unit, VehicleCategory, VehicleCategoryOption } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

const digitsToInteger = (value: string): number => parseInt(value.replace(/\D/g, '') || '0', 10);

export default function Form({ unit, sites, vehicleCategories }: { unit?: Unit; sites: Site[]; vehicleCategories: VehicleCategoryOption[] }) {
    const form = useForm({
        site_id: unit?.site_id ?? sites[0]?.id ?? '',
        customer: unit?.customer ?? '',
        current_plate: unit?.current_plate ?? '',
        type: unit?.type ?? '',
        brand: unit?.brand ?? '',
        vehicle_category: unit?.vehicle_category ?? 'pickup_suv',
        year: unit?.year ?? new Date().getFullYear(),
        current_odo: unit?.current_odo ?? 0,
        status: unit?.status ?? 'active',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        unit ? form.patch(route('units.update', unit.id)) : form.post(route('units.store'));
    };

    return (
        <Card>
            <CardContent>
                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-6 sm:grid-cols-2">
                        <div className="space-y-2"><InputLabel htmlFor="site_id" value="Site" /><Select value={String(form.data.site_id)} onValueChange={(value) => form.setData('site_id', Number(value))}><SelectTrigger id="site_id"><SelectValue /></SelectTrigger><SelectContent>{sites.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.site_id} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="customer" value="Customer" /><TextInput id="customer" className="block w-full" value={form.data.customer} onChange={(e) => form.setData('customer', e.target.value)} required /><InputError message={form.errors.customer} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="current_plate" value="Current Plate" /><TextInput id="current_plate" className="block w-full" value={form.data.current_plate} onChange={(e) => form.setData('current_plate', e.target.value.toUpperCase())} required /><InputError message={form.errors.current_plate} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="type" value="Type" /><TextInput id="type" className="block w-full" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} required /><InputError message={form.errors.type} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="brand" value="Brand" /><TextInput id="brand" className="block w-full" value={form.data.brand} onChange={(e) => form.setData('brand', e.target.value)} required /><InputError message={form.errors.brand} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="vehicle_category" value="Kategori Kendaraan" /><Select value={form.data.vehicle_category} onValueChange={(value) => form.setData('vehicle_category', value as VehicleCategory)}><SelectTrigger id="vehicle_category"><SelectValue /></SelectTrigger><SelectContent>{vehicleCategories.map((category) => <SelectItem key={category.value} value={category.value}>{category.label}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.vehicle_category} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="year" value="Year" /><TextInput id="year" type="text" inputMode="numeric" pattern="[0-9]*" className="block w-full" value={String(form.data.year)} onChange={(e) => form.setData('year', digitsToInteger(e.target.value))} required /><InputError message={form.errors.year} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="current_odo" value="Current ODO" /><TextInput id="current_odo" type="text" inputMode="numeric" pattern="[0-9]*" min="0" className="block w-full" value={String(form.data.current_odo)} onChange={(e) => form.setData('current_odo', digitsToInteger(e.target.value))} required /><InputError message={form.errors.current_odo} /></div>
                        <div className="space-y-2"><InputLabel htmlFor="status" value="Status" /><Select value={form.data.status} onValueChange={(value) => form.setData('status', value)}><SelectTrigger id="status"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="active">Active</SelectItem><SelectItem value="breakdown">Breakdown</SelectItem><SelectItem value="inactive">Inactive</SelectItem></SelectContent></Select><InputError message={form.errors.status} /></div>
                    </div>
                    {unit?.plate_histories && <div><h3 className="text-sm font-medium text-foreground">Plate History</h3><ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">{unit.plate_histories.map((history) => <li key={history.id}>{history.plate_number}: {history.active_from} - {history.active_until ?? 'Now'}</li>)}</ul></div>}
                    <div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Save</PrimaryButton><Link href={route('units.index')} className="text-sm text-muted-foreground hover:text-foreground">Cancel</Link></div>
                </form>
            </CardContent>
        </Card>
    );
}
