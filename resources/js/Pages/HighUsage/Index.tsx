import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { HighUsageFlag, PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface ResourceCollection<T> {
    data: T[];
}

export default function Index({ flags, canTakeAction }: PageProps<{ flags: ResourceCollection<HighUsageFlag>; canTakeAction: boolean }>) {
    const [selectedFlag, setSelectedFlag] = useState<HighUsageFlag | null>(null);
    const scheduleForm = useForm({ new_due_km: '', new_due_date: '' });

    const takeAction = (flag: HighUsageFlag, action: 'triggered' | 'deferred') => {
        router.post(route('high-usage.action', flag.id), { action }, { preserveScroll: true });
    };

    const openSchedule = (flag: HighUsageFlag) => {
        setSelectedFlag(flag);
        scheduleForm.setData({ new_due_km: flag.unit_planning?.next_due_km?.toString() ?? '', new_due_date: flag.unit_planning?.next_due_date ?? '' });
    };

    const submitSchedule = (event: FormEvent) => {
        event.preventDefault();

        if (!selectedFlag) {
            return;
        }

        scheduleForm.post(route('high-usage.schedule', selectedFlag.id), {
            preserveScroll: true,
            onSuccess: () => setSelectedFlag(null),
        });
    };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">High Usage</h2>}><Head title="High Usage" /><div className="py-12"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><div className="overflow-hidden bg-white shadow-sm sm:rounded-lg"><div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200"><thead className="bg-gray-50"><tr><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Unit</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Site</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Item</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Avg KM/Hari</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Estimasi Due</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Flagged At</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Window</th><th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Aksi</th></tr></thead><tbody className="divide-y divide-gray-200 bg-white">{flags.data.map((flag) => <tr key={flag.id}><td className="px-4 py-3 text-sm font-medium text-gray-900">{flag.unit?.current_plate}</td><td className="px-4 py-3 text-sm text-gray-600">{flag.unit?.site?.name}</td><td className="px-4 py-3 text-sm text-gray-600">{flag.planning_item?.name}</td><td className="px-4 py-3 text-sm text-gray-600">{flag.avg_km_per_day.toLocaleString('id-ID')}</td><td className="px-4 py-3 text-sm text-gray-600">{flag.estimated_due_days} hari</td><td className="px-4 py-3 text-sm text-gray-600">{flag.flagged_at?.slice(0, 10)}</td><td className="px-4 py-3 text-sm"><span className={flag.window === 1 ? 'rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700' : 'rounded-full bg-orange-50 px-2 py-1 text-xs font-medium text-orange-700'}>{flag.window === 1 ? 'Window 1' : 'Window 2'} · hari ke-{flag.days_since_flagged}</span></td><td className="px-4 py-3 text-sm"><div className="flex flex-wrap gap-2">{canTakeAction && !flag.action_taken && <><PrimaryButton type="button" onClick={() => takeAction(flag, 'triggered')}>Buat Task Sekarang</PrimaryButton><SecondaryButton type="button" onClick={() => takeAction(flag, 'deferred')}>Tunda</SecondaryButton></>}{canTakeAction && flag.window === 2 && <SecondaryButton type="button" onClick={() => openSchedule(flag)}>Input Jadwal Baru</SecondaryButton>}</div></td></tr>)}{flags.data.length === 0 && <tr><td colSpan={8} className="px-4 py-8 text-center text-sm text-gray-500">Belum ada flag High Usage aktif.</td></tr>}</tbody></table></div></div></div></div><Modal show={selectedFlag !== null} onClose={() => setSelectedFlag(null)}><form onSubmit={submitSchedule} className="space-y-6 p-6"><div><h2 className="text-lg font-medium text-gray-900">Input Jadwal Baru</h2><p className="mt-1 text-sm text-gray-600">Ajukan due KM dan due date baru untuk approval SPV.</p></div><div><InputLabel htmlFor="new_due_km" value="New Due KM" /><TextInput id="new_due_km" type="number" className="mt-1 block w-full" value={scheduleForm.data.new_due_km} onChange={(event) => scheduleForm.setData('new_due_km', event.target.value)} /><InputError className="mt-2" message={scheduleForm.errors.new_due_km} /></div><div><InputLabel htmlFor="new_due_date" value="New Due Date" /><TextInput id="new_due_date" type="date" className="mt-1 block w-full" value={scheduleForm.data.new_due_date} onChange={(event) => scheduleForm.setData('new_due_date', event.target.value)} /><InputError className="mt-2" message={scheduleForm.errors.new_due_date} /></div><div className="flex justify-end gap-3"><SecondaryButton type="button" onClick={() => setSelectedFlag(null)}>Batal</SecondaryButton><PrimaryButton disabled={scheduleForm.processing}>Submit Jadwal</PrimaryButton></div></form></Modal></AuthenticatedLayout>;
}
