import PaginationLinks from '@/Components/PaginationLinks';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, UnitHistory, UnitHistoryItem, UnitPlateHistory } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ReactNode } from 'react';

type UnitHistoryPageProps = PageProps<{
    history: UnitHistory;
}>;

export default function History({ history }: UnitHistoryPageProps) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">History Unit {history.unit.current_plate}</h2>}>
            <Head title={`History ${history.unit.current_plate}`} />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded-xl border bg-card p-6 shadow-xs">
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h3 className="text-base font-semibold text-foreground">{history.unit.current_plate}</h3>
                                <p className="mt-1 text-sm text-muted-foreground">{history.unit.brand} {history.unit.type} · {history.unit.year} · {history.unit.site}</p>
                                <p className="mt-1 text-sm text-muted-foreground">Customer: {history.unit.customer} · ODO: {history.unit.current_odo.toLocaleString('id-ID')} KM · Status: {history.unit.status}</p>
                            </div>
                            <Link href={route('reports.index')} className="text-sm font-medium text-primary hover:underline">Kembali ke laporan</Link>
                        </div>
                    </div>

                    <Section title="Riwayat Penggantian" empty="Belum ada penggantian complete." meta={history.replacements.meta}>
                        {history.replacements.data.map((item) => <HistoryCard key={item.id} item={item} />)}
                    </Section>

                    <Section title="Riwayat Plat Nomor" empty="Belum ada perubahan plat nomor." meta={history.plate_histories.meta}>
                        {history.plate_histories.data.map((plate) => <PlateCard key={plate.id} plate={plate} />)}
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

function Section({ title, empty, children, meta }: { title: string; empty: string; children: ReactNode; meta: UnitHistory['replacements']['meta'] }) {
    const items = Array.isArray(children) ? children.filter(Boolean) : children;

    return <section className="rounded-xl border bg-card p-6 shadow-xs"><h3 className="text-base font-semibold text-foreground">{title}</h3><div className="mt-4 space-y-3">{Array.isArray(items) && items.length === 0 ? <p className="text-sm text-muted-foreground">{empty}</p> : items}</div><PaginationLinks meta={meta} /></section>;
}

function HistoryCard({ item, showReason = false }: { item: UnitHistoryItem; showReason?: boolean }) {
    return <div className="rounded-xl border bg-muted/20 p-4"><div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between"><div className="font-medium text-foreground">{item.planning_item ?? 'Item maintenance'}</div><div className="text-sm text-muted-foreground">WO #{item.work_order_id} · {item.completed_date ?? item.created_at ?? '-'}</div></div><div className="mt-2 grid grid-cols-1 gap-2 text-sm text-gray-600 md:grid-cols-3"><span>Status: {item.status}</span><span>ODO: {item.completed_odo?.toLocaleString('id-ID') ?? '-'}</span><span>Petugas: {item.submitted_by ?? '-'}</span></div>{(item.previous_due_km || item.previous_due_date || item.new_due_km || item.new_due_date) && <div className="mt-3 grid grid-cols-1 gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 md:grid-cols-2"><span>Due lama: {item.previous_due_km?.toLocaleString('id-ID') ?? '-'} KM · {item.previous_due_date ?? '-'}</span><span>Due baru: {item.new_due_km?.toLocaleString('id-ID') ?? '-'} KM · {item.new_due_date ?? '-'}</span></div>}{(showReason || item.reason) && <p className="mt-3 rounded-lg bg-muted/40 p-3 text-sm text-muted-foreground">Alasan: {item.reason ?? '-'}</p>}{item.notes && <p className="mt-2 text-sm text-muted-foreground">Catatan: {item.notes}</p>}</div>;
}

function PlateCard({ plate }: { plate: UnitPlateHistory }) {
    return <div className="rounded-xl border bg-muted/20 p-4 text-sm text-foreground"><div className="font-medium text-foreground">{plate.plate_number}</div><div className="mt-1 text-muted-foreground">Aktif: {plate.active_from} sampai {plate.active_until ?? 'sekarang'}</div></div>;
}
