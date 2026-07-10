import InputError from '@/Components/InputError';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, UnitHistory, UnitHistoryItem, UnitPlateHistory, UnitSiteTransfer } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';

type UnitHistoryPageProps = PageProps<{
    history: UnitHistory;
}>;

export default function History({ history }: UnitHistoryPageProps) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">History Unit {history.unit.current_plate}</h2>}>
            <Head title={`History ${history.unit.current_plate}`} />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Card><CardContent>
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h3 className="text-base font-semibold text-foreground">{history.unit.current_plate}</h3>
                                {history.unit.needs_document_verification && <div className="mt-2"><StatusBadge tone="warning">Perlu Verifikasi Dokumen</StatusBadge></div>}
                                <p className="mt-1 text-sm text-muted-foreground">{history.unit.brand} {history.unit.type} · {history.unit.year} · {history.unit.site}</p>
                                <p className="mt-1 text-sm text-muted-foreground">Customer: {history.unit.customer} · ODO: {history.unit.current_odo.toLocaleString('id-ID')} KM · Status: {history.unit.status}</p>
                            </div>
                            <Link href={route('reports.index')} className="text-sm font-medium text-primary hover:underline">Kembali ke laporan</Link>
                        </div>
                    </CardContent></Card>

                    {history.can_request_transfer && <TransferRequestForm history={history} />}
                    {history.can_approve_transfer && history.pending_transfers.length > 0 && <PendingTransferApprovals transfers={history.pending_transfers} />}

                    <Section title="Riwayat Penggantian" empty="Belum ada penggantian complete." meta={history.replacements.meta}>
                        {history.replacements.data.map((item) => <HistoryCard key={item.id} item={item} />)}
                    </Section>

                    <Section title="Riwayat Plat Nomor" empty="Belum ada perubahan plat nomor." meta={history.plate_histories.meta}>
                        {history.plate_histories.data.map((plate) => <PlateCard key={plate.id} plate={plate} />)}
                    </Section>

                    <Section title="Riwayat Pindah Site" empty="Belum ada pengajuan pindah site." meta={history.site_transfers.meta}>
                        {history.site_transfers.data.map((transfer) => <TransferCard key={transfer.id} transfer={transfer} />)}
                    </Section>

                    <Section title="Riwayat Blocked/Breakdown" empty="Belum ada blocked atau breakdown." meta={history.blocked_breakdowns.meta}>
                        {history.blocked_breakdowns.data.map((item) => <HistoryCard key={item.id} item={item} />)}
                    </Section>

                    <Section title="Riwayat Postpone" empty="Belum ada postpone." meta={history.postpones.meta}>
                        {history.postpones.data.map((item) => <HistoryCard key={item.id} item={item} showReason />)}
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function TransferRequestForm({ history }: { history: UnitHistory }) {
    const form = useForm({ to_site_id: '', reason: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('units.site-transfers.store', history.unit.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return <Card><CardContent><h3 className="text-base font-semibold text-foreground">Pindah Site</h3><p className="mt-1 text-sm text-muted-foreground">Ajukan pindah site unit. Site unit tidak berubah sampai Spv HO approve.</p><form onSubmit={submit} className="mt-4 grid gap-4 md:grid-cols-[1fr_2fr_auto]"><div><Select value={form.data.to_site_id} onValueChange={(value) => form.setData('to_site_id', value)}><SelectTrigger><SelectValue placeholder="Pilih site baru" /></SelectTrigger><SelectContent>{history.transfer_sites.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}</SelectContent></Select><InputError className="mt-2" message={form.errors.to_site_id} /></div><div><Textarea className="min-h-11 text-sm" placeholder="Alasan (opsional)" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} /><InputError className="mt-2" message={form.errors.reason} /></div><PrimaryButton disabled={form.processing}>Ajukan</PrimaryButton></form></CardContent></Card>;
}

function PendingTransferApprovals({ transfers }: { transfers: UnitSiteTransfer[] }) {
    return <Card className="border-amber-200 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/15"><CardContent><h3 className="text-base font-semibold text-amber-900 dark:text-amber-100">Approval Pindah Site Pending</h3><div className="mt-4 space-y-3">{transfers.map((transfer) => <TransferApprovalCard key={transfer.id} transfer={transfer} />)}</div></CardContent></Card>;
}

function TransferApprovalCard({ transfer }: { transfer: UnitSiteTransfer }) {
    const form = useForm({ decision_reason: '' });
    const decide = (action: 'approve' | 'reject') => {
        form.post(route(action === 'approve' ? 'unit-site-transfers.approve' : 'unit-site-transfers.reject', transfer.id), { preserveScroll: true });
    };

    return <Card><CardContent className="p-4"><div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between"><div><div className="font-medium text-foreground">{transfer.unit_plate ?? 'Unit'}: {transfer.from_site} → {transfer.to_site}</div><p className="mt-1 text-sm text-muted-foreground">Diajukan oleh {transfer.requested_by ?? '-'} pada {transfer.requested_at ?? '-'}</p>{transfer.reason && <p className="mt-2 text-sm text-muted-foreground">Alasan: {transfer.reason}</p>}</div><div className="flex flex-wrap gap-2"><PrimaryButton type="button" disabled={form.processing} onClick={() => decide('approve')}>Approve</PrimaryButton><SecondaryButton type="button" disabled={form.processing} onClick={() => decide('reject')}>Reject</SecondaryButton></div></div><Textarea className="mt-3 text-sm" placeholder="Catatan approve/reject (opsional)" value={form.data.decision_reason} onChange={(event) => form.setData('decision_reason', event.target.value)} /><InputError className="mt-2" message={form.errors.decision_reason} /></CardContent></Card>;
}

function Section({ title, empty, children, meta }: { title: string; empty: string; children: ReactNode; meta: UnitHistory['replacements']['meta'] }) {
    const items = Array.isArray(children) ? children.filter(Boolean) : children;

    return <Card><CardContent><h3 className="text-base font-semibold text-foreground">{title}</h3><div className="mt-4 space-y-3">{Array.isArray(items) && items.length === 0 ? <p className="text-sm text-muted-foreground">{empty}</p> : items}</div><PaginationLinks meta={meta} /></CardContent></Card>;
}

function HistoryCard({ item, showReason = false }: { item: UnitHistoryItem; showReason?: boolean }) {
    return <Card className="bg-muted/20"><CardContent className="p-4"><div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between"><div className="font-medium text-foreground">{item.planning_item ?? 'Item maintenance'}</div><div className="text-sm text-muted-foreground">WO #{item.work_order_id} · {item.completed_date ?? item.created_at ?? '-'}</div></div><div className="mt-2 grid grid-cols-1 gap-2 text-sm text-muted-foreground md:grid-cols-3"><span>Status: {item.status}</span><span>ODO: {item.completed_odo?.toLocaleString('id-ID') ?? '-'}</span><span>Petugas: {item.submitted_by ?? '-'}</span></div>{(item.previous_due_km || item.previous_due_date || item.new_due_km || item.new_due_date) && <div className="mt-3 grid grid-cols-1 gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-200 md:grid-cols-2"><span>Due lama: {item.previous_due_km?.toLocaleString('id-ID') ?? '-'} KM · {item.previous_due_date ?? '-'}</span><span>Due baru: {item.new_due_km?.toLocaleString('id-ID') ?? '-'} KM · {item.new_due_date ?? '-'}</span></div>}{(showReason || item.reason) && <p className="mt-3 rounded-lg bg-muted/40 p-3 text-sm text-muted-foreground">Alasan: {item.reason ?? '-'}</p>}{item.notes && <p className="mt-2 text-sm text-muted-foreground">Catatan: {item.notes}</p>}</CardContent></Card>;
}

function PlateCard({ plate }: { plate: UnitPlateHistory }) {
    return <Card className="bg-muted/20 text-sm text-foreground"><CardContent className="p-4"><div className="font-medium text-foreground">{plate.plate_number}</div><div className="mt-1 text-muted-foreground">Aktif: {plate.active_from} sampai {plate.active_until ?? 'sekarang'}</div></CardContent></Card>;
}

function TransferCard({ transfer }: { transfer: UnitSiteTransfer }) {
    return <Card className="bg-muted/20 text-sm text-foreground"><CardContent className="p-4"><div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between"><div className="font-medium text-foreground">{transfer.from_site} → {transfer.to_site}</div><div className="text-muted-foreground">{transfer.status} · {transfer.requested_at ?? '-'}</div></div><div className="mt-2 grid gap-2 text-muted-foreground md:grid-cols-3"><span>Pengaju: {transfer.requested_by ?? '-'}</span><span>Approver: {transfer.approved_by ?? '-'}</span><span>Diproses: {transfer.approved_at ?? '-'}</span></div>{transfer.reason && <p className="mt-3 rounded-lg bg-muted/40 p-3 text-muted-foreground">Alasan: {transfer.reason}</p>}{transfer.decision_reason && <p className="mt-2 rounded-lg bg-muted/40 p-3 text-muted-foreground">Catatan keputusan: {transfer.decision_reason}</p>}</CardContent></Card>;
}
