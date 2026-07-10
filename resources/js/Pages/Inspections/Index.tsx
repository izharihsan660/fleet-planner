import ConfirmDialog from '@/Components/ConfirmDialog';
import PrimaryButton from '@/Components/PrimaryButton';
import PaginationLinks from '@/Components/PaginationLinks';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { InspectionLog, PageProps, PaginatedCollection, Unit } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type ResourceCollection<T> = T[] | { data: T[] };

const collectionData = <T,>(collection: ResourceCollection<T>): T[] => (Array.isArray(collection) ? collection : collection.data);

export default function Index({ auth, inspectionLogs, units, filters }: PageProps<{ inspectionLogs: PaginatedCollection<InspectionLog>; units: ResourceCollection<Unit>; filters: { unit_id?: string; inspection_date?: string } }>) {
    const unitData = collectionData(units);
    const inspectionLogData = collectionData(inspectionLogs);
    const canCreate = ['superadmin', 'planner_area', 'mekanik'].includes(auth.user.role);
    const [unitId, setUnitId] = useState(filters.unit_id ?? '');
    const [inspectionDate, setInspectionDate] = useState(filters.inspection_date ?? '');
    const [cancelLog, setCancelLog] = useState<InspectionLog | null>(null);
    const cancelForm = useForm({});
    const filter = (event: FormEvent) => { event.preventDefault(); router.get(route('inspections.index'), { unit_id: unitId || undefined, inspection_date: inspectionDate || undefined }, { preserveState: true }); };
    const confirmCancel = () => {
        if (!cancelLog) {
            return;
        }

        cancelForm.delete(route('inspections.cancel-today', cancelLog.id), {
            preserveScroll: true,
            onSuccess: () => setCancelLog(null),
        });
    };

    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Log Inspeksi KM</h2>}><Head title="Log Inspeksi KM" /><div className="py-10"><div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8"><div className="flex items-center justify-between">{canCreate && <Link href={route('inspections.create')}><PrimaryButton>Input KM Harian</PrimaryButton></Link>}</div><Card><CardContent><form onSubmit={filter} className="grid gap-4 md:grid-cols-3"><Select value={unitId || 'all'} onValueChange={(value) => setUnitId(value === 'all' ? '' : value)}><SelectTrigger><SelectValue placeholder="Semua Unit" /></SelectTrigger><SelectContent><SelectItem value="all">Semua Unit</SelectItem>{unitData.map((unit) => <SelectItem key={unit.id} value={String(unit.id)}>{unit.current_plate}</SelectItem>)}</SelectContent></Select><TextInput type="date" value={inspectionDate} onChange={(event) => setInspectionDate(event.target.value)} /><PrimaryButton>Filter</PrimaryButton></form></CardContent></Card><Card><CardContent className="space-y-4"><div className="overflow-x-auto"><Table><TableHeader><TableRow>{['Tanggal','Unit','Odometer','Mekanik','Site','Aksi'].map((head) => <TableHead key={head}>{head}</TableHead>)}</TableRow></TableHeader><TableBody>{inspectionLogData.map((log) => <TableRow key={log.id}><TableCell>{log.inspection_date}</TableCell><TableCell className="font-medium text-foreground">{log.unit?.current_plate}</TableCell><TableCell>{log.odometer.toLocaleString('id-ID')}</TableCell><TableCell>{log.mechanic?.name}</TableCell><TableCell>{log.unit?.site?.name}</TableCell><TableCell>{log.can_cancel_today && <SecondaryButton type="button" className="min-h-12" onClick={() => setCancelLog(log)}>Batalkan Input Hari Ini</SecondaryButton>}</TableCell></TableRow>)}{inspectionLogData.length === 0 && <TableRow><TableCell colSpan={6} className="py-8 text-center text-muted-foreground">Belum ada log inspeksi.</TableCell></TableRow>}</TableBody></Table></div><PaginationLinks meta={inspectionLogs.meta} /></CardContent></Card></div></div><ConfirmDialog show={cancelLog !== null} title="Batalkan input hari ini?" message={`Input KM untuk ${cancelLog?.unit?.current_plate ?? 'unit ini'} akan dihapus. Setelah itu unit bisa diinput ulang.`} confirmLabel="Ya, batalkan" processing={cancelForm.processing} onCancel={() => setCancelLog(null)} onConfirm={confirmCancel} /></AuthenticatedLayout>;
}
