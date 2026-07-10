import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Unit } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Gauge, MapPin } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

type ResourceCollection<T> = T[] | { data: T[] };

type FlashProps = {
    flash?: {
        status?: string | null;
    };
};

const collectionData = <T,>(collection: ResourceCollection<T>): T[] => (Array.isArray(collection) ? collection : collection.data);
const digitsToInteger = (value: string): number => parseInt(value.replace(/\D/g, '') || '0', 10);

function SuccessNotice({ show }: { show: boolean }) {
    if (!show) {
        return null;
    }

    return (
        <div className="fixed inset-x-4 top-6 z-50 mx-auto max-w-sm rounded-3xl border border-green-200 bg-green-50 p-6 text-center text-green-800 shadow-xl">
            <CheckCircle2 className="mx-auto size-16 text-green-600" />
            <p className="mt-3 text-xl font-bold">Berhasil disimpan</p>
        </div>
    );
}

function MechanicInputKm({ units, today }: { units: Unit[]; today: string }) {
    const { flash } = usePage<PageProps & FlashProps>().props;
    const [selectedUnit, setSelectedUnit] = useState<Unit | null>(null);
    const [showSuccess, setShowSuccess] = useState(flash?.status === 'Berhasil disimpan');
    const form = useForm({ unit_id: '', inspection_date: today, odometer: '' });

    useEffect(() => {
        if (flash?.status !== 'Berhasil disimpan') {
            return;
        }

        setShowSuccess(true);
        const timer = window.setTimeout(() => setShowSuccess(false), 3000);

        return () => window.clearTimeout(timer);
    }, [flash?.status]);

    const chooseUnit = (unit: Unit) => {
        setSelectedUnit(unit);
        form.setData({ unit_id: String(unit.id), inspection_date: today, odometer: String(unit.current_odo) });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('inspections.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSelectedUnit(null);
            },
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-foreground">Input KM</h2>}>
            <Head title="Input KM" />
            <SuccessNotice show={showSuccess} />
            <div className="py-6 sm:py-10">
                <div className="mx-auto max-w-2xl space-y-5 px-4 sm:px-6 lg:px-8">
                    {!selectedUnit && (
                        <>
                            <div>
                                <p className="text-2xl font-bold text-foreground">Pilih Unit</p>
                                <p className="mt-1 text-base text-muted-foreground">Tap kartu unit yang mau diinput KM-nya.</p>
                            </div>
                            {units.length === 0 && (
                                <Card className="rounded-3xl">
                                    <CardContent className="p-8 text-center">
                                        <CheckCircle2 className="mx-auto size-16 text-green-600" />
                                        <p className="mt-4 text-xl font-bold text-foreground">Semua unit sudah diinput hari ini.</p>
                                        <p className="mt-2 text-base text-muted-foreground">Kerja bagus!</p>
                                    </CardContent>
                                </Card>
                            )}
                            {units.map((unit) => (
                                <button key={unit.id} type="button" onClick={() => chooseUnit(unit)} className="w-full rounded-3xl border-2 bg-card p-5 text-left shadow-sm transition hover:border-primary focus:border-primary focus:outline-none">
                                    <div className="flex items-start gap-4">
                                        <div className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                            <Gauge className="size-8" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-2xl font-bold leading-tight text-foreground">{unit.current_plate}</p>
                                            <div className="mt-2 flex items-center gap-2 text-base text-muted-foreground">
                                                <MapPin className="size-5" />
                                                <span>{unit.site?.name ?? 'Site belum diisi'}</span>
                                            </div>
                                            <p className="mt-3 text-base text-muted-foreground">KM terakhir: <span className="font-bold text-foreground">{unit.current_odo.toLocaleString('id-ID')}</span></p>
                                        </div>
                                    </div>
                                </button>
                            ))}
                        </>
                    )}

                    {selectedUnit && (
                        <Card className="rounded-3xl border-2">
                            <CardContent className="space-y-5 p-5">
                                <div>
                                    <p className="text-2xl font-bold text-foreground">{selectedUnit.current_plate}</p>
                                    <p className="mt-2 text-base text-muted-foreground">Masukkan KM sekarang.</p>
                                </div>
                                <form onSubmit={submit} className="space-y-5">
                                    <div className="rounded-2xl bg-muted p-4 text-base text-muted-foreground">
                                        KM terakhir: <span className="font-bold text-foreground">{selectedUnit.current_odo.toLocaleString('id-ID')}</span>
                                    </div>
                                    <div>
                                        <label htmlFor="odometer" className="mb-2 block text-lg font-semibold text-foreground">KM sekarang</label>
                                        <TextInput id="odometer" type="number" inputMode="numeric" min="0" className="h-16 w-full rounded-2xl text-2xl font-bold" value={form.data.odometer} onChange={(event) => form.setData('odometer', event.target.value)} required autoFocus />
                                        <InputError className="mt-2 text-base" message={form.errors.odometer} />
                                    </div>
                                    <Button disabled={form.processing} className="min-h-14 w-full rounded-2xl text-lg font-bold">Simpan</Button>
                                    <Button type="button" variant="secondary" className="min-h-12 w-full rounded-2xl text-base" onClick={() => setSelectedUnit(null)}>Pilih Unit Lain</Button>
                                </form>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function DefaultInputKm({ units, today, minimumInspectionData }: { units: Unit[]; today: string; minimumInspectionData: number }) {
    const form = useForm({ unit_id: units[0]?.id ?? '', inspection_date: today, odometer: units[0]?.current_odo ?? 0 });
    const selectedUnit = useMemo(() => units.find((unit) => unit.id === Number(form.data.unit_id)), [form.data.unit_id, units]);
    const hasInsufficientData = selectedUnit ? (selectedUnit.inspection_logs_count ?? 0) < minimumInspectionData : false;
    const submit = (event: FormEvent) => { event.preventDefault(); form.post(route('inspections.store')); };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Input KM Harian</h2>}><Head title="Input KM Harian" /><div className="py-10"><div className="mx-auto max-w-3xl sm:px-6 lg:px-8"><Card><CardContent><form onSubmit={submit} className="space-y-6"><div className="space-y-2"><InputLabel htmlFor="unit_id" value="Unit" /><Select value={String(form.data.unit_id)} onValueChange={(value) => { const unit = units.find((item) => item.id === Number(value)); form.setData((data) => ({ ...data, unit_id: Number(value), odometer: unit?.current_odo ?? data.odometer })); }}><SelectTrigger id="unit_id"><SelectValue placeholder="Pilih unit" /></SelectTrigger><SelectContent>{units.map((unit) => <SelectItem key={unit.id} value={String(unit.id)}>{unit.current_plate} - {unit.site?.name}</SelectItem>)}</SelectContent></Select><InputError message={form.errors.unit_id} /></div>{selectedUnit && <div className="rounded-xl bg-muted/50 p-4 text-sm text-muted-foreground"><p>ODO saat ini: <span className="font-semibold text-foreground">{selectedUnit.current_odo.toLocaleString('id-ID')}</span></p><p>Rata-rata KM/hari: <span className="font-semibold text-foreground">{selectedUnit.avg_km_per_day?.toLocaleString('id-ID') ?? '-'}</span></p></div>}{hasInsufficientData && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-200">Data inspeksi unit ini masih kurang dari threshold ({minimumInspectionData} data), rata-rata pemakaian belum akurat.</div>}<div className="grid gap-6 sm:grid-cols-2"><div className="space-y-2"><InputLabel htmlFor="inspection_date" value="Tanggal Inspeksi" /><TextInput id="inspection_date" type="date" max={today} className="block w-full" value={form.data.inspection_date} onChange={(event) => form.setData('inspection_date', event.target.value)} required /><InputError message={form.errors.inspection_date} /></div><div className="space-y-2"><InputLabel htmlFor="odometer" value="Odometer" /><TextInput id="odometer" type="text" inputMode="numeric" pattern="[0-9]*" min="0" className="block w-full" value={String(form.data.odometer)} onChange={(event) => form.setData('odometer', digitsToInteger(event.target.value))} required /><InputError message={form.errors.odometer} /></div></div><div className="flex items-center gap-3"><PrimaryButton disabled={form.processing}>Simpan KM</PrimaryButton><Link href={route('inspections.index')} className="text-sm text-muted-foreground hover:text-foreground">Lihat Log</Link></div></form></CardContent></Card></div></div></AuthenticatedLayout>;
}

export default function Create({ auth, units, today, minimumInspectionData }: PageProps<{ units: ResourceCollection<Unit>; today: string; minimumInspectionData: number }>) {
    const unitData = collectionData(units);

    if (auth.user.role === 'mekanik') {
        return <MechanicInputKm units={unitData} today={today} />;
    }

    return <DefaultInputKm units={unitData} today={today} minimumInspectionData={minimumInspectionData} />;
}
