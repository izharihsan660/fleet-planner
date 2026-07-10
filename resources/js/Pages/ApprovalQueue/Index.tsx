import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Region } from '@/types';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

type ApprovalQueueItem = {
    id: number;
    work_order_id: number;
    plate_number: string;
    item_name: string;
    reason: string | null;
    site_name: string;
    region_name: string;
    submitted_by_name: string;
    submitted_at: string | null;
    waiting_hours: number;
    waiting_label: string;
    is_warning: boolean;
    status: 'replace' | 'postpone' | 'pending_create';
};

type ApprovalQueueProps = PageProps<{
    items: ApprovalQueueItem[];
    regions: { data: Region[] };
    filters: {
        region_id: string;
        search: string;
    };
}>;

export default function Index({ items, regions, filters }: ApprovalQueueProps) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [filterRegionId, setFilterRegionId] = useState(filters.region_id ?? '');
    const [search, setSearch] = useState(filters.search ?? '');
    const [isActionPanelOpen, setIsActionPanelOpen] = useState(false);
    const [showApproveConfirm, setShowApproveConfirm] = useState(false);
    const [showRejectConfirm, setShowRejectConfirm] = useState(false);
    const [isVerificationExpanded, setIsVerificationExpanded] = useState(false);

    const selectedItems = useMemo(() => items.filter((item) => selectedIds.includes(item.id)), [items, selectedIds]);
    const visibleVerificationItems = isVerificationExpanded ? selectedItems : selectedItems.slice(0, 4);
    const hiddenVerificationCount = selectedItems.length - visibleVerificationItems.length;

    const form = useForm<{ decision: 'approve' | 'reject'; item_ids: number[]; reason: string }>({
        decision: 'approve',
        item_ids: [],
        reason: '',
    });

    const selectedSummary = `${selectedItems.length} item dipilih`;

    const toggleItem = (itemId: number) => {
        setSelectedIds((current) => current.includes(itemId) ? current.filter((id) => id !== itemId) : [...current, itemId]);
    };

    const toggleAll = () => {
        setSelectedIds((current) => current.length === items.length ? [] : items.map((item) => item.id));
    };

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('approval-queue.index'), { region_id: filterRegionId, search }, { preserveState: true, replace: true });
    };

    const openApproveConfirm = () => {
        form.setData({ decision: 'approve', item_ids: selectedIds, reason: '' });
        setShowApproveConfirm(true);
    };

    const openRejectConfirm = () => {
        form.setData({ decision: 'reject', item_ids: selectedIds, reason: form.data.reason });
        setShowRejectConfirm(true);
    };

    const submitDecision = () => {
        form.post(route('approval-queue.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedIds([]);
                setIsActionPanelOpen(false);
                setShowApproveConfirm(false);
                setShowRejectConfirm(false);
                setIsVerificationExpanded(false);
                form.reset();
            },
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Antrian Approval</h2>}>
            <Head title="Antrian Approval" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <section className="rounded-xl border bg-card p-6 shadow-xs">
                        <div className="space-y-2">
                            <h3 className="text-lg font-semibold text-foreground">Antrian Approval Spv HO</h3>
                            <p className="text-sm text-muted-foreground">Periksa pengajuan dari semua region, lalu setujui atau tolak beberapa item sekaligus.</p>
                        </div>

                        <form onSubmit={applyFilters} className="mt-6 grid gap-4 md:grid-cols-[220px_1fr_auto] md:items-end">
                            <div>
                                <InputLabel htmlFor="region_id" value="Region" />
                                <select id="region_id" className="mt-1 w-full rounded-lg border-border bg-background p-3 text-base shadow-xs" value={filterRegionId} onChange={(event) => setFilterRegionId(event.target.value)}>
                                    <option value="">Semua Region</option>
                                    {regions.data.map((region) => <option key={region.id} value={region.id}>{region.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="search" value="Cari plat atau nama item" />
                                <TextInput id="search" className="mt-1 w-full p-3 text-base" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Contoh: KT 8994 atau Filter Oli" />
                            </div>
                            <PrimaryButton className="h-12 px-6" type="submit">Cari</PrimaryButton>
                        </form>
                    </section>

                    <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                            <div>
                                <h3 className="font-semibold text-foreground">Item menunggu keputusan</h3>
                                <p className="text-sm text-muted-foreground">{items.length} item menunggu approval. Urutan paling lama menunggu ada di atas.</p>
                            </div>
                            {selectedItems.length > 0 && <span className="rounded-full bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700">{selectedItems.length} dipilih</span>}
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y text-left text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th className="w-14 px-4 py-4">
                                            <input aria-label="Pilih semua item" type="checkbox" checked={items.length > 0 && selectedIds.length === items.length} onChange={toggleAll} className="h-5 w-5 rounded border-gray-300" />
                                        </th>
                                        <th className="px-4 py-4 text-base font-semibold">Plat Nomor</th>
                                        <th className="px-4 py-4 text-base font-semibold">Item & Alasan</th>
                                        <th className="px-4 py-4 text-base font-semibold">Site</th>
                                        <th className="px-4 py-4 text-base font-semibold">Diajukan Oleh</th>
                                        <th className="px-4 py-4 text-base font-semibold">Lama Menunggu</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {items.map((item) => (
                                        <tr key={item.id} className={selectedIds.includes(item.id) ? 'bg-indigo-50/50' : 'bg-card'}>
                                            <td className="px-4 py-4">
                                                <input aria-label={`Pilih ${item.plate_number} ${item.item_name}`} type="checkbox" checked={selectedIds.includes(item.id)} onChange={() => toggleItem(item.id)} className="h-5 w-5 rounded border-gray-300" />
                                            </td>
                                            <td className="px-4 py-4 text-base font-semibold text-foreground">{item.plate_number}</td>
                                            <td className="px-4 py-4">
                                                <p className="text-base font-medium text-foreground">{item.item_name}</p>
                                                <p className="mt-1 text-sm text-muted-foreground">{item.reason || 'Tidak ada alasan tambahan.'}</p>
                                            </td>
                                            <td className="px-4 py-4 text-base text-muted-foreground">{item.site_name}<span className="block text-sm">{item.region_name}</span></td>
                                            <td className="px-4 py-4 text-base text-muted-foreground">{item.submitted_by_name}</td>
                                            <td className="px-4 py-4">
                                                <span className={item.is_warning ? 'rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-sm font-semibold text-orange-700' : 'rounded-full border border-yellow-200 bg-yellow-50 px-3 py-1 text-sm font-semibold text-yellow-700'}>{item.waiting_label}</span>
                                            </td>
                                        </tr>
                                    ))}
                                    {items.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="px-4 py-12 text-center text-base text-muted-foreground">Belum ada item yang menunggu approval.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {selectedItems.length > 0 && <div className="h-24" />}

                    {selectedItems.length > 0 && !isActionPanelOpen && (
                        <div className="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 p-4 shadow-2xl backdrop-blur">
                            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                                <button type="button" className="text-left" onClick={() => setIsActionPanelOpen(true)}>
                                    <p className="text-base font-semibold text-foreground">{selectedSummary} — Lanjutkan →</p>
                                    <p className="text-sm text-muted-foreground">Klik untuk membuka daftar verifikasi dan keputusan.</p>
                                </button>
                                <div className="flex flex-wrap gap-2">
                                    <SecondaryButton type="button" onClick={() => setSelectedIds([])}>Batal pilih</SecondaryButton>
                                    <PrimaryButton type="button" onClick={() => setIsActionPanelOpen(true)}>Lanjutkan</PrimaryButton>
                                </div>
                            </div>
                        </div>
                    )}

                    {selectedItems.length > 0 && isActionPanelOpen && (
                        <>
                            <button type="button" aria-label="Tutup panel approval" className="fixed inset-0 z-30 bg-black/20" onClick={() => setIsActionPanelOpen(false)} />
                            <div className="fixed inset-x-0 bottom-0 z-40 rounded-t-2xl border bg-background shadow-2xl">
                                <div className="mx-auto max-w-7xl">
                                    <div className="flex flex-wrap items-start justify-between gap-3 border-b p-5">
                                        <div>
                                            <h3 className="text-lg font-semibold text-foreground">Kirim keputusan</h3>
                                            <p className="text-sm text-muted-foreground">{selectedSummary}</p>
                                        </div>
                                        <SecondaryButton type="button" onClick={() => setIsActionPanelOpen(false)}>Tutup</SecondaryButton>
                                    </div>

                                    <div className="max-h-[70vh] overflow-y-auto p-5">
                                        <div className="rounded-lg bg-muted/40 p-4">
                                            <p className="mb-2 text-sm font-medium text-foreground">Item yang dipilih:</p>
                                            <ul className="space-y-1 text-sm text-muted-foreground">
                                                {visibleVerificationItems.map((item) => <li key={item.id}>{item.plate_number} — {item.item_name}</li>)}
                                            </ul>
                                            {hiddenVerificationCount > 0 && (
                                                <button type="button" className="mt-2 text-sm font-medium text-indigo-700 hover:underline" onClick={() => setIsVerificationExpanded(true)}>
                                                    dan {hiddenVerificationCount} lainnya
                                                </button>
                                            )}
                                            {isVerificationExpanded && selectedItems.length > 4 && (
                                                <button type="button" className="mt-2 block text-sm font-medium text-indigo-700 hover:underline" onClick={() => setIsVerificationExpanded(false)}>
                                                    Tampilkan lebih sedikit
                                                </button>
                                            )}
                                        </div>

                                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                            <button type="button" className="rounded-xl bg-green-600 px-6 py-4 text-base font-semibold text-white shadow-sm transition hover:bg-green-700" onClick={openApproveConfirm}>Setuju</button>
                                            <button type="button" className="rounded-xl bg-red-600 px-6 py-4 text-base font-semibold text-white shadow-sm transition hover:bg-red-700" onClick={openRejectConfirm}>Tolak</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>

            <ConfirmDialog
                show={showApproveConfirm}
                title="Setujui pengajuan"
                message={`Setujui ${selectedItems.length} item?`}
                confirmLabel="Setuju"
                processing={form.processing}
                onCancel={() => setShowApproveConfirm(false)}
                onConfirm={submitDecision}
            />

            <Dialog open={showRejectConfirm} onOpenChange={(open) => !open && setShowRejectConfirm(false)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Tolak pengajuan</DialogTitle>
                        <DialogDescription>Tolak {selectedItems.length} item? Tulis alasan singkat agar Planner Area tahu apa yang perlu diperbaiki.</DialogDescription>
                    </DialogHeader>
                    <div>
                        <InputLabel value="Alasan penolakan" />
                        <textarea className="mt-1 w-full rounded-lg border-border bg-background p-3 text-base shadow-xs" rows={4} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} placeholder="Contoh: Jadwal belum sesuai, mohon lengkapi alasan." />
                        <InputError className="mt-2" message={form.errors.reason} />
                    </div>
                    <DialogFooter>
                        <SecondaryButton type="button" disabled={form.processing} onClick={() => setShowRejectConfirm(false)}>Batal</SecondaryButton>
                        <button type="button" className="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold uppercase tracking-widest text-white transition hover:bg-red-700 disabled:opacity-25" disabled={form.processing || form.data.reason.trim() === ''} onClick={submitDecision}>Tolak</button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
