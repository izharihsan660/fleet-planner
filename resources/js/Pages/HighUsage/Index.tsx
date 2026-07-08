import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { HighUsageFlag, PageProps, PaginatedCollection } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

export default function Index({ flags, canTakeAction }: PageProps<{ flags: PaginatedCollection<HighUsageFlag>; canTakeAction: boolean }>) {
    const [selectedFlag, setSelectedFlag] = useState<HighUsageFlag | null>(null);
    const [actionConfirmation, setActionConfirmation] = useState<{ flag: HighUsageFlag; action: 'triggered' | 'deferred' } | null>(null);
    const [confirmSchedule, setConfirmSchedule] = useState(false);
    const scheduleForm = useForm({ available_date: '', new_due_km: '', new_due_date: '' });

    const takeAction = (flag: HighUsageFlag, action: 'triggered' | 'deferred') => {
        setActionConfirmation({ flag, action });
    };

    const confirmTakeAction = () => {
        if (!actionConfirmation) {
            return;
        }

        router.post(route('high-usage.action', actionConfirmation.flag.id), { action: actionConfirmation.action }, { preserveScroll: true, onFinish: () => setActionConfirmation(null) });
    };

    const openSchedule = (flag: HighUsageFlag) => {
        setSelectedFlag(flag);
        scheduleForm.setData({ available_date: '', new_due_km: flag.unit_planning?.next_due_km?.toString() ?? '', new_due_date: flag.unit_planning?.next_due_date ?? '' });
    };

    const submitSchedule = (event: FormEvent) => {
        event.preventDefault();

        if (!selectedFlag) {
            return;
        }

        setConfirmSchedule(true);
    };

    const confirmSubmitSchedule = () => {
        if (!selectedFlag) {
            return;
        }

        scheduleForm.post(route('high-usage.schedule', selectedFlag.id), {
            preserveScroll: true,
            onSuccess: () => setSelectedFlag(null),
            onFinish: () => setConfirmSchedule(false),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">High Usage</h2>}>
            <Head title="High Usage" />
            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <Card>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {['Unit', 'Site', 'Item', 'Avg KM/Hari', 'Estimasi Due', 'Flagged At', 'Window', 'Aksi'].map((header) => <TableHead key={header}>{header}</TableHead>)}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {flags.data.map((flag) => (
                                            <TableRow key={flag.id}>
                                                <TableCell className="font-medium text-foreground">{flag.unit?.current_plate}</TableCell>
                                                <TableCell>{flag.unit?.site?.name}</TableCell>
                                                <TableCell>{flag.planning_item?.name}</TableCell>
                                                <TableCell>{flag.avg_km_per_day.toLocaleString('id-ID')}</TableCell>
                                                <TableCell>{flag.estimated_due_days} hari</TableCell>
                                                <TableCell>{flag.flagged_at?.slice(0, 10)}</TableCell>
                                                <TableCell>
                                                    <StatusBadge tone={flag.window === 1 ? 'info' : 'highUsage'}>
                                                        {flag.window === 1 ? 'Window 1' : 'Window 2'} · hari ke-{flag.days_since_flagged}
                                                    </StatusBadge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-2">
                                                        {canTakeAction && !flag.action_taken && (
                                                            <>
                                                                <PrimaryButton type="button" onClick={() => takeAction(flag, 'triggered')}>Ya</PrimaryButton>
                                                                <SecondaryButton type="button" onClick={() => takeAction(flag, 'deferred')}>Tidak</SecondaryButton>
                                                            </>
                                                        )}
                                                        {canTakeAction && flag.window === 2 && <SecondaryButton type="button" onClick={() => openSchedule(flag)}>Input Jadwal Baru</SecondaryButton>}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {flags.data.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={8} className="py-10 text-center text-muted-foreground">Belum ada flag High Usage aktif.</TableCell>
                                            </TableRow>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                            <PaginationLinks meta={flags.meta} />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog open={selectedFlag !== null} onOpenChange={(open) => !open && setSelectedFlag(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Input Jadwal Baru</DialogTitle>
                        <DialogDescription>Ajukan tanggal unit bisa dipegang, due KM, dan due date baru untuk approval SPV.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitSchedule} className="space-y-5">
                        {Object.keys(scheduleForm.errors).length > 0 && <p className="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive">Submit gagal. Periksa tanggal dan KM yang wajib diisi sebelum kirim ke SPV.</p>}
                        <div className="space-y-2">
                            <Label htmlFor="available_date">Kapan Unit Bisa Dipegang</Label>
                            <Input id="available_date" type="date" value={scheduleForm.data.available_date} onChange={(event) => scheduleForm.setData('available_date', event.target.value)} />
                            <InputError message={scheduleForm.errors.available_date} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="new_due_km">New Due KM</Label>
                            <Input id="new_due_km" type="number" value={scheduleForm.data.new_due_km} onChange={(event) => scheduleForm.setData('new_due_km', event.target.value)} />
                            <InputError message={scheduleForm.errors.new_due_km} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="new_due_date">New Due Date</Label>
                            <Input id="new_due_date" type="date" value={scheduleForm.data.new_due_date} onChange={(event) => scheduleForm.setData('new_due_date', event.target.value)} />
                            <InputError message={scheduleForm.errors.new_due_date} />
                        </div>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={() => setSelectedFlag(null)}>Batal</SecondaryButton>
                            <PrimaryButton disabled={scheduleForm.processing}>Submit ke SPV</PrimaryButton>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog show={actionConfirmation !== null} message={`${actionConfirmation?.action === 'triggered' ? 'Approve Buat Task Sekarang' : 'Reject High Usage'} untuk ${actionConfirmation?.flag.unit?.current_plate ?? 'unit'} - ${actionConfirmation?.flag.planning_item?.name ?? 'item'}?`} onCancel={() => setActionConfirmation(null)} onConfirm={confirmTakeAction} />
            <ConfirmDialog show={confirmSchedule} message={`Submit jadwal High Usage untuk ${selectedFlag?.unit?.current_plate ?? 'unit'} - ${selectedFlag?.planning_item?.name ?? 'item'} pada ${scheduleForm.data.available_date || '-'}?`} processing={scheduleForm.processing} onCancel={() => setConfirmSchedule(false)} onConfirm={confirmSubmitSchedule} />
        </AuthenticatedLayout>
    );
}
