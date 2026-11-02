import ConfirmDialog from '@/Components/ConfirmDialog';
import PaginationLinks from '@/Components/PaginationLinks';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { ManualProjection, PaginatedCollection } from '@/types';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function ManualProjectionTab({ entries }: { entries: PaginatedCollection<ManualProjection> }) {
    const [cancelling, setCancelling] = useState<ManualProjection | null>(null);
    const form = useForm({});
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
                        <TableHead>Unit</TableHead><TableHead>Item</TableHead><TableHead>Tanggal Ganti</TableHead><TableHead>Alasan</TableHead><TableHead>Catatan</TableHead><TableHead>Dibuat Oleh</TableHead><TableHead>Tanggal Dibuat</TableHead><TableHead>Aksi</TableHead>
                    </TableRow></TableHeader>
                    <TableBody>
                        {entries.data.map((entry) => <TableRow key={entry.id}>
                            <TableCell className="font-medium">{entry.plate_number}<span className="block text-xs text-muted-foreground">{entry.site_name}</span></TableCell>
                            <TableCell>{entry.planning_item_name}</TableCell>
                            <TableCell className="whitespace-nowrap">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(entry.target_date))}</TableCell>
                            <TableCell>{entry.reason_label}</TableCell>
                            <TableCell className="max-w-72 whitespace-pre-wrap break-words">{entry.notes || '-'}</TableCell>
                            <TableCell>{entry.created_by_name}</TableCell>
                            <TableCell className="whitespace-nowrap">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(entry.created_at))}</TableCell>
                            <TableCell>{entry.can_cancel && <Button variant="outline" size="sm" onClick={() => setCancelling(entry)}>Batalkan</Button>}</TableCell>
                        </TableRow>)}
                        {entries.data.length === 0 && <TableRow><TableCell colSpan={8} className="py-8 text-center text-muted-foreground">Tidak ada kebutuhan manual aktif.</TableCell></TableRow>}
                    </TableBody>
                </Table>
            </div>
            <PaginationLinks meta={entries.meta} />
            <ConfirmDialog show={cancelling !== null} title="Batalkan Kebutuhan Manual?" message={`Kebutuhan ${cancelling?.planning_item_name ?? ''} untuk ${cancelling?.plate_number ?? ''} akan dihapus dari input manual proyeksi. Due sistem tetap berlaku.`} confirmLabel="Ya, Batalkan" processing={form.processing} onCancel={() => !form.processing && setCancelling(null)} onConfirm={cancel} />
        </div>
    );
}
