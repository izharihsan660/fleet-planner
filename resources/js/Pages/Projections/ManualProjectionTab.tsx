import ConfirmDialog from '@/Components/ConfirmDialog';
import PaginationLinks from '@/Components/PaginationLinks';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { ManualProjection, PaginatedCollection } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function ManualProjectionTab({ entries }: { entries: PaginatedCollection<ManualProjection> }) {
    const [cancelling, setCancelling] = useState<ManualProjection | null>(null);
    const form = useForm<{ manual_projection?: string }>({});
    const cancel = () => {
        if (!cancelling) {
            return;
        }
        form.patch(route('projections.manual.cancel', cancelling.id), { preserveScroll: true, onSuccess: () => setCancelling(null) });
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">Kebutuhan manual aktif pada wilayah/lokasi terpilih, termasuk tanggal di luar periode proyeksi.</p>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader><TableRow>
                        <TableHead>Unit</TableHead><TableHead>Item</TableHead><TableHead>Tanggal Ganti / Jadwal Kerja</TableHead><TableHead>Mekanik</TableHead><TableHead>Status Task</TableHead><TableHead>Alasan</TableHead><TableHead>Catatan</TableHead><TableHead>Dibuat Oleh</TableHead><TableHead>Tanggal Dibuat</TableHead><TableHead>Aksi</TableHead>
                    </TableRow></TableHeader>
                    <TableBody>
                        {entries.data.map((entry) => <TableRow key={entry.id}>
                            <TableCell className="font-medium">{entry.plate_number}<span className="block text-xs text-muted-foreground">{entry.site_name}</span></TableCell>
                            <TableCell>{entry.planning_item_name}</TableCell>
                            <TableCell className="whitespace-nowrap">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(entry.target_date))}</TableCell>
                            <TableCell>{entry.assigned_mechanic_name || '-'}</TableCell>
                            <TableCell className="whitespace-nowrap">
                                <span className="block">{entry.task_status_label || '-'}</span>
                                {entry.work_order_id !== null && <Link href={route('work-orders.show', entry.work_order_id)} className="text-xs text-primary underline underline-offset-4">Detail WO #{entry.work_order_id}</Link>}
                            </TableCell>
                            <TableCell>{entry.reason_label}</TableCell>
                            <TableCell className="max-w-72 whitespace-pre-wrap break-words">{entry.notes || '-'}</TableCell>
                            <TableCell>{entry.created_by_name}</TableCell>
                            <TableCell className="whitespace-nowrap">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(entry.created_at))}</TableCell>
                            <TableCell>{entry.can_cancel && <Button variant="outline" size="sm" onClick={() => { form.clearErrors(); setCancelling(entry); }}>Batalkan</Button>}</TableCell>
                        </TableRow>)}
                        {entries.data.length === 0 && <TableRow><TableCell colSpan={10} className="py-8 text-center text-muted-foreground">Tidak ada kebutuhan manual aktif.</TableCell></TableRow>}
                    </TableBody>
                </Table>
            </div>
            <PaginationLinks meta={entries.meta} />
            <ConfirmDialog show={cancelling !== null} title="Batalkan Kebutuhan Manual?" message={form.errors.manual_projection ?? `Kebutuhan ${cancelling?.planning_item_name ?? ''} untuk ${cancelling?.plate_number ?? ''} akan dibatalkan. Task baru yang belum disetujui ikut dibatalkan; task lama yang hanya ditautkan tidak diubah. Due sistem tetap berlaku.`} confirmLabel="Ya, Batalkan" processing={form.processing} onCancel={() => !form.processing && setCancelling(null)} onConfirm={cancel} />
        </div>
    );
}
