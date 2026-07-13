import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Region } from '@/types';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
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
                                <Select value={filterRegionId || 'all'} onValueChange={(value) => setFilterRegionId(value === 'all' ? '' : value)}>
                                    <SelectTrigger id="region_id" className="mt-1 h-12 w-full text-base">
                                        <SelectValue placeholder="Semua Region" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Region</SelectItem>
                                        {regions.data.map((region) => <SelectItem key={region.id} value={String(region.id)}>{region.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
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
                            {selectedItems.length > 0 && <span className="rounded-full bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200">{selectedItems.length} dipilih</span>}
                        </div>

                        <div>
                            <Table className="min-w-full text-left">
                                <TableHeader className="bg-muted/50 text-muted-foreground">
                                    <TableRow>
                                        <TableHead className="w-14 px-4 py-4">
                                            <Checkbox aria-label="Pilih semua item" checked={items.length > 0 && selectedIds.length === items.length} onCheckedChange={toggleAll} />
                                        </TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Plat Nomor</TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Item & Alasan</TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Site</TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Diajukan Oleh</TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Lama Menunggu</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody className="divide-y">
                                    {items.map((item) => (
                                        <TableRow key={item.id} className={selectedIds.includes(item.id) ? 'bg-indigo-50/50 dark:bg-indigo-500/10' : 'bg-card'}>
                                            <TableCell className="px-4 py-4">
                                                <Checkbox aria-label={`Pilih ${item.plate_number} ${item.item_name}`} checked={selectedIds.includes(item.id)} onCheckedChange={() => toggleItem(item.id)} />
                                            </TableCell>
                                            <TableCell className="px-4 py-4 text-base font-semibold text-foreground">{item.plate_number}</TableCell>
                                            <TableCell className="px-4 py-4">
                                                <p className="text-base font-medium text-foreground">{item.item_name}</p>
                                                <p className="mt-1 text-sm text-muted-foreground">{item.reason || 'Tidak ada alasan tambahan.'}</p>
                                            </TableCell>
                                            <TableCell className="px-4 py-4 text-base text-muted-foreground">{item.site_name}<span className="block text-sm">{item.region_name}</span></TableCell>
                                            <TableCell className="px-4 py-4 text-base text-muted-foreground">{item.submitted_by_name}</TableCell>
                                            <TableCell className="px-4 py-4">
                                                <span className={item.is_warning ? 'rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-sm font-semibold text-orange-700 dark:border-orange-500/40 dark:bg-orange-500/15 dark:text-orange-200' : 'rounded-full border border-yellow-200 bg-yellow-50 px-3 py-1 text-sm font-semibold text-yellow-700 dark:border-yellow-500/40 dark:bg-yellow-500/15 dark:text-yellow-200'}>{item.waiting_label}</span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {items.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={6} className="px-4 py-12 text-center text-base text-muted-foreground">Belum ada item yang menunggu approval.</TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </section>

                    {selectedItems.length > 0 && <div className="h-24" />}

                    {selectedItems.length > 0 && !isActionPanelOpen && (
                        <div className="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 p-4 shadow-2xl backdrop-blur">
                            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                                <Button type="button" variant="ghost" className="h-auto justify-start p-0 text-left hover:bg-transparent" onClick={() => setIsActionPanelOpen(true)}>
                                    <span>
                                    <p className="text-base font-semibold text-foreground">{selectedSummary} — Lanjutkan →</p>
                                    <p className="text-sm text-muted-foreground">Klik untuk membuka daftar verifikasi dan keputusan.</p>
                                    </span>
                                </Button>
                                <div className="flex flex-wrap gap-2">
                                    <SecondaryButton type="button" onClick={() => setSelectedIds([])}>Batal pilih</SecondaryButton>
                                    <PrimaryButton type="button" onClick={() => setIsActionPanelOpen(true)}>Lanjutkan</PrimaryButton>
                                </div>
                            </div>
                        </div>
                    )}

                    {selectedItems.length > 0 && isActionPanelOpen && (
                        <>
                            <Button type="button" aria-label="Tutup panel approval" variant="ghost" className="fixed inset-0 z-30 h-auto rounded-none bg-black/20 p-0 hover:bg-black/20" onClick={() => setIsActionPanelOpen(false)} />
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
                                                <Button type="button" variant="link" className="mt-2 h-auto p-0 text-sm font-medium text-indigo-700 dark:text-indigo-300" onClick={() => setIsVerificationExpanded(true)}>
                                                    dan {hiddenVerificationCount} lainnya
                                                </Button>
                                            )}
                                            {isVerificationExpanded && selectedItems.length > 4 && (
                                                <Button type="button" variant="link" className="mt-2 h-auto p-0 text-sm font-medium text-indigo-700 dark:text-indigo-300" onClick={() => setIsVerificationExpanded(false)}>
                                                    Tampilkan lebih sedikit
                                                </Button>
                                            )}
                                        </div>

                                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                            <Button type="button" className="h-auto rounded-xl bg-green-600 px-6 py-4 text-base font-semibold text-white shadow-sm transition hover:bg-green-700" onClick={openApproveConfirm}>Setuju</Button>
                                            <Button type="button" variant="destructive" className="h-auto rounded-xl px-6 py-4 text-base font-semibold shadow-sm" onClick={openRejectConfirm}>Tolak</Button>
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
                        <Textarea className="mt-1 p-3 text-base" rows={4} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} placeholder="Contoh: Jadwal belum sesuai, mohon lengkapi alasan." />
                        <InputError className="mt-2" message={form.errors.reason} />
                    </div>
                    <DialogFooter>
                        <SecondaryButton type="button" disabled={form.processing} onClick={() => setShowRejectConfirm(false)}>Batal</SecondaryButton>
                        <Button type="button" variant="destructive" className="uppercase tracking-widest" disabled={form.processing || form.data.reason.trim() === ''} onClick={submitDecision}>Tolak</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
