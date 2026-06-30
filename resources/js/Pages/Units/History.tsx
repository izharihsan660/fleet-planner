import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, UnitHistory, UnitHistoryItem, UnitPlateHistory } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ReactNode } from 'react';

type UnitHistoryPageProps = PageProps<{
    history: UnitHistory;
}>;

export default function History({ history }: UnitHistoryPageProps) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">History Unit {history.unit.current_plate}</h2>}>
            <Head title={`History ${history.unit.current_plate}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">{history.unit.current_plate}</h3>
                                <p className="mt-1 text-sm text-gray-600">{history.unit.brand} {history.unit.type} · {history.unit.year} · {history.unit.site}</p>
                                <p className="mt-1 text-sm text-gray-600">Customer: {history.unit.customer} · ODO: {history.unit.current_odo.toLocaleString('id-ID')} KM · Status: {history.unit.status}</p>
                            </div>
                            <Link href={route('reports.index')} className="text-sm font-medium text-indigo-600 hover:text-indigo-800">Kembali ke laporan</Link>
                        </div>
                    </div>

                    <Section title="Riwayat Penggantian" empty="Belum ada penggantian complete.">
                        {history.replacements.map((item) => <HistoryCard key={item.id} item={item} />)}
                    </Section>

                    <Section title="Riwayat Plat Nomor" empty="Belum ada perubahan plat nomor.">
                        {history.plate_histories.map((plate) => <PlateCard key={plate.id} plate={plate} />)}
                    </Section>

                    <Section title="Riwayat Blocked/Breakdown" empty="Belum ada blocked atau breakdown.">
                        {history.blocked_breakdowns.map((item) => <HistoryCard key={item.id} item={item} />)}
                    </Section>

                    <Section title="Riwayat Postpone" empty="Belum ada postpone.">
                        {history.postpones.map((item) => <HistoryCard key={item.id} item={item} showReason />)}
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Section({ title, empty, children }: { title: string; empty: string; children: ReactNode }) {
    const items = Array.isArray(children) ? children.filter(Boolean) : children;

    return <section className="rounded-lg bg-white p-6 shadow-sm"><h3 className="text-lg font-semibold text-gray-900">{title}</h3><div className="mt-4 space-y-3">{Array.isArray(items) && items.length === 0 ? <p className="text-sm text-gray-500">{empty}</p> : items}</div></section>;
}

function HistoryCard({ item, showReason = false }: { item: UnitHistoryItem; showReason?: boolean }) {
    return <div className="rounded-lg border border-gray-200 p-4"><div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between"><div className="font-medium text-gray-900">{item.planning_item ?? 'Item maintenance'}</div><div className="text-sm text-gray-500">WO #{item.work_order_id} · {item.completed_date ?? item.created_at ?? '-'}</div></div><div className="mt-2 grid grid-cols-1 gap-2 text-sm text-gray-600 md:grid-cols-3"><span>Status: {item.status}</span><span>ODO: {item.completed_odo?.toLocaleString('id-ID') ?? '-'}</span><span>Petugas: {item.submitted_by ?? '-'}</span></div>{(showReason || item.reason) && <p className="mt-3 rounded-md bg-gray-50 p-3 text-sm text-gray-700">Alasan: {item.reason ?? '-'}</p>}{item.notes && <p className="mt-2 text-sm text-gray-600">Catatan: {item.notes}</p>}</div>;
}

function PlateCard({ plate }: { plate: UnitPlateHistory }) {
    return <div className="rounded-lg border border-gray-200 p-4 text-sm text-gray-700"><div className="font-medium text-gray-900">{plate.plate_number}</div><div className="mt-1">Aktif: {plate.active_from} sampai {plate.active_until ?? 'sekarang'}</div></div>;
}
