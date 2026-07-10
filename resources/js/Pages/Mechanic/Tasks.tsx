import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Battery, CalendarDays, CheckCircle2, CircleCheckBig, Disc3, Droplet, Gauge, Hammer, Wrench } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

type MechanicTask = {
    id: number;
    work_order_id: number;
    unit_name: string;
    item_name: string;
    scheduled_date: string | null;
    current_odo: number;
    site_name: string | null;
};

type FlashProps = {
    flash?: {
        status?: string | null;
    };
};

function itemIcon(itemName: string) {
    const normalizedName = itemName.toLowerCase();

    if (normalizedName.includes('ban') || normalizedName.includes('tire')) {
        return Disc3;
    }

    if (normalizedName.includes('accu') || normalizedName.includes('battery') || normalizedName.includes('aki')) {
        return Battery;
    }

    if (normalizedName.includes('oli') || normalizedName.includes('oil')) {
        return Droplet;
    }

    if (normalizedName.includes('greasing') || normalizedName.includes('gemuk')) {
        return Hammer;
    }

    return Wrench;
}

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

export default function Tasks({ tasks }: PageProps<{ tasks: MechanicTask[] }>) {
    const { flash } = usePage<PageProps & FlashProps>().props;
    const [selectedTask, setSelectedTask] = useState<MechanicTask | null>(null);
    const [showSuccess, setShowSuccess] = useState(flash?.status === 'Berhasil disimpan');
    const form = useForm({ completed_odo: '', completed_date: new Date().toISOString().slice(0, 10) });

    useEffect(() => {
        if (flash?.status !== 'Berhasil disimpan') {
            return;
        }

        setShowSuccess(true);
        const timer = window.setTimeout(() => setShowSuccess(false), 3000);

        return () => window.clearTimeout(timer);
    }, [flash?.status]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!selectedTask) {
            return;
        }

        form.post(route('work-orders.items.complete', [selectedTask.work_order_id, selectedTask.id]), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSelectedTask(null);
            },
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-foreground">Tugas Saya</h2>}>
            <Head title="Tugas Saya" />
            <SuccessNotice show={showSuccess} />
            <div className="py-6 sm:py-10">
                <div className="mx-auto max-w-2xl space-y-5 px-4 sm:px-6 lg:px-8">
                    <div>
                        <p className="text-2xl font-bold text-foreground">Tugas Saya</p>
                        <p className="mt-1 text-base text-muted-foreground">Pilih pekerjaan, lalu tekan tombol Selesai.</p>
                    </div>

                    {tasks.length === 0 && (
                        <Card className="rounded-3xl">
                            <CardContent className="p-8 text-center">
                                <CircleCheckBig className="mx-auto size-16 text-green-600" />
                                <p className="mt-4 text-xl font-bold text-foreground">Tidak ada tugas saat ini</p>
                                <p className="mt-2 text-base text-muted-foreground">Semua pekerjaan yang ditugaskan sudah selesai.</p>
                            </CardContent>
                        </Card>
                    )}

                    {tasks.map((task) => {
                        const Icon = itemIcon(task.item_name);

                        return (
                            <Card key={task.id} className="rounded-3xl border-2 shadow-sm">
                                <CardContent className="space-y-5 p-5">
                                    <div className="flex items-start gap-4">
                                        <div className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                            <Icon className="size-8" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-2xl font-bold leading-tight text-foreground">{task.unit_name}</p>
                                            <p className="mt-2 text-lg font-semibold text-foreground">{task.item_name}</p>
                                            <div className="mt-3 flex items-center gap-2 text-base text-muted-foreground">
                                                <CalendarDays className="size-5" />
                                                <span>{task.scheduled_date ?? 'Belum dijadwalkan'}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <Button type="button" className="min-h-14 w-full rounded-2xl text-lg font-bold" onClick={() => { setSelectedTask(task); form.setData('completed_odo', String(task.current_odo)); }}>
                                        Selesai
                                    </Button>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            </div>

            {selectedTask && (
                <div className="fixed inset-0 z-40 flex items-end bg-black/40 p-0 sm:items-center sm:p-6">
                    <div className="w-full rounded-t-3xl bg-background p-5 shadow-xl sm:mx-auto sm:max-w-md sm:rounded-3xl">
                        <div className="mb-5">
                            <p className="text-2xl font-bold text-foreground">Selesaikan Tugas</p>
                            <p className="mt-2 text-lg text-muted-foreground">{selectedTask.unit_name} · {selectedTask.item_name}</p>
                        </div>
                        <form onSubmit={submit} className="space-y-5">
                            <div className="rounded-2xl bg-muted p-4 text-base text-muted-foreground">
                                KM terakhir: <span className="font-bold text-foreground">{selectedTask.current_odo.toLocaleString('id-ID')}</span>
                            </div>
                            <div>
                                <label htmlFor="completed_odo" className="mb-2 block text-lg font-semibold text-foreground">KM saat ini</label>
                                <TextInput id="completed_odo" type="number" inputMode="numeric" min="0" className="h-16 w-full rounded-2xl text-2xl font-bold" value={form.data.completed_odo} onChange={(event) => form.setData('completed_odo', event.target.value)} required autoFocus />
                                <InputError className="mt-2 text-base" message={form.errors.completed_odo} />
                            </div>
                            <Button disabled={form.processing} className="min-h-14 w-full rounded-2xl text-lg font-bold">Simpan</Button>
                            <Button type="button" variant="secondary" className="min-h-12 w-full rounded-2xl text-base" onClick={() => setSelectedTask(null)}>Batal</Button>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
