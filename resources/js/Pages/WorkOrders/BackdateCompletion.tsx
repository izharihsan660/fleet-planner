import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { BackdateNotice } from '@/Components/WorkOrderItemActionForms';
import { Card, CardContent } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BackdateThresholds, PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type BackdateWorkOrder = {
    id: number;
    plate_number: string;
    site_name: string;
    current_odo: number;
};

type BackdateItem = {
    id: number;
    item_name: string;
    status: string;
    last_done_date: string | null;
    last_done_km: number | null;
};

export default function BackdateCompletion({ workOrder, item, backdateThresholds }: PageProps<{ workOrder: BackdateWorkOrder; item: BackdateItem; backdateThresholds: BackdateThresholds }>) {
    const { data, setData, post, processing, errors } = useForm({
        completed_odo: String(workOrder.current_odo),
        completed_date: new Date().toISOString().slice(0, 10),
        notes: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('work-orders.items.backdate-completion.update', [workOrder.id, item.id]));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Koreksi Tanggal Selesai</h2>}>
            <Head title="Koreksi Tanggal Selesai" />

            <div className="py-10">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Link href={route('work-orders.show', workOrder.id)}>
                        <SecondaryButton>Kembali ke WO</SecondaryButton>
                    </Link>

                    <Card>
                        <CardContent className="space-y-5 p-6">
                            <div>
                                <h3 className="text-base font-semibold text-foreground">{item.item_name}</h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    WO #{workOrder.id} · {workOrder.plate_number} · {workOrder.site_name}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Penyelesaian sebelumnya: {item.last_done_date ?? '-'} · KM {item.last_done_km?.toLocaleString('id-ID') ?? '-'}
                                </p>
                            </div>

                            <div role="alert" className="rounded-lg border-2 border-red-400 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/50 dark:bg-red-500/15 dark:text-red-200">
                                <p className="font-semibold">Jalur khusus Superadmin.</p>
                                <p className="mt-1">
                                    Batas mundur {backdateThresholds.max_days} hari tidak berlaku di sini. Setiap koreksi tercatat atas nama Anda dan
                                    ditandai di riwayat unit, jadi isi alasannya dengan jelas.
                                </p>
                            </div>

                            <form onSubmit={submit} className="space-y-4">
                                <BackdateNotice completedDate={data.completed_date} thresholds={backdateThresholds} />

                                <div>
                                    <label htmlFor="completed_date" className="mb-1 block text-sm font-medium text-foreground">Tanggal selesai sebenarnya</label>
                                    <TextInput id="completed_date" type="date" className="w-full" value={data.completed_date} onChange={(event) => setData('completed_date', event.target.value)} />
                                    <InputError message={errors.completed_date} className="mt-1" />
                                </div>

                                <div>
                                    <label htmlFor="completed_odo" className="mb-1 block text-sm font-medium text-foreground">KM saat pekerjaan dilakukan</label>
                                    <TextInput id="completed_odo" type="number" min="0" className="w-full" value={data.completed_odo} onChange={(event) => setData('completed_odo', event.target.value)} />
                                    <InputError message={errors.completed_odo} className="mt-1" />
                                </div>

                                <div>
                                    <label htmlFor="notes" className="mb-1 block text-sm font-medium text-foreground">
                                        Alasan koreksi (minimal {backdateThresholds.extended_note_min_length} karakter)
                                    </label>
                                    <Textarea id="notes" className="w-full" rows={4} value={data.notes} onChange={(event) => setData('notes', event.target.value)} />
                                    <p className="mt-1 text-xs text-muted-foreground">{data.notes.trim().length} karakter</p>
                                    <InputError message={errors.notes} className="mt-1" />
                                </div>

                                <div className="flex gap-2">
                                    <PrimaryButton disabled={processing}>Simpan Koreksi</PrimaryButton>
                                    <Link href={route('work-orders.show', workOrder.id)}>
                                        <SecondaryButton type="button">Batal</SecondaryButton>
                                    </Link>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
