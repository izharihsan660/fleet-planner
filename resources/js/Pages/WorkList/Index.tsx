import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Site, User } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

type WorkListItem = {
    id: number;
    work_order_id: number;
    site_id: number;
    site_name: string;
    plate_number: string;
    item_name: string;
    status: 'on_hold' | 'overdue';
    due_date: string | null;
    due_km: number | null;
    late_days: number;
    status_label: string;
};

type SiteAction = {
    site_id: number;
    action: 'replace' | 'postpone' | 'blocked';
    assigned_mechanic_id: string;
    scheduled_date: string;
};

type WorkListProps = PageProps<{
    items: WorkListItem[];
    sites: { data: Site[] };
    mechanicsBySite: Record<string, Pick<User, 'id' | 'name' | 'site_id'>[]>;
    filters: {
        site_id: string;
        search: string;
    };
}>;

const actionLabels: Record<SiteAction['action'], string> = {
    replace: 'Ajukan Ganti',
    postpone: 'Tunda',
    blocked: 'Blokir',
};

const todayInputValue = () => {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

export default function Index({ auth, items, sites, mechanicsBySite, filters }: WorkListProps) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [filterSiteId, setFilterSiteId] = useState(filters.site_id ?? '');
    const [search, setSearch] = useState(filters.search ?? '');
    const [showConfirm, setShowConfirm] = useState(false);
    const [isActionPanelOpen, setIsActionPanelOpen] = useState(false);
    const [expandedItemSites, setExpandedItemSites] = useState<number[]>([]);
    const [bulkAction, setBulkAction] = useState<SiteAction['action']>('replace');
    const [bulkScheduledDate, setBulkScheduledDate] = useState(todayInputValue());

    const selectedItems = useMemo(
        () => items.filter((item) => selectedIds.includes(item.id)),
        [items, selectedIds],
    );

    const selectedSiteIds = useMemo(
        () => Array.from(new Set(selectedItems.map((item) => item.site_id))),
        [selectedItems],
    );

    const [siteActions, setSiteActions] = useState<Record<number, SiteAction>>({});

    const form = useForm<{ groups: Array<{ site_id: number; action: SiteAction['action']; item_ids: number[]; assigned_mechanic_id: string | null; scheduled_date: string }> }>({
        groups: [],
    });

    const canSubmit = ['superadmin', 'planner_area'].includes(auth.user.role);

    const defaultMechanicIdForSite = (siteId: number): string => {
        const mechanics = mechanicsBySite[String(siteId)] ?? [];

        return mechanics.length === 1 ? String(mechanics[0].id) : '';
    };

    const defaultSiteAction = (siteId: number): SiteAction => ({
        site_id: siteId,
        action: 'replace',
        assigned_mechanic_id: defaultMechanicIdForSite(siteId),
        scheduled_date: todayInputValue(),
    });

    const selectedGroups = useMemo(() => selectedSiteIds.map((siteId) => {
        const siteItems = selectedItems.filter((item) => item.site_id === siteId);
        const defaultAction = siteActions[siteId] ?? defaultSiteAction(siteId);

        return {
            siteId,
            siteName: siteItems[0]?.site_name ?? 'Lokasi',
            items: siteItems,
            form: defaultAction,
        };
    }), [mechanicsBySite, selectedItems, selectedSiteIds, siteActions]);

    const updateSiteAction = (siteId: number, values: Partial<SiteAction>) => {
        setSiteActions((current) => ({
            ...current,
            [siteId]: {
                ...current[siteId],
                site_id: siteId,
                action: current[siteId]?.action ?? 'replace',
                assigned_mechanic_id: current[siteId]?.assigned_mechanic_id ?? defaultMechanicIdForSite(siteId),
                scheduled_date: current[siteId]?.scheduled_date ?? todayInputValue(),
                ...values,
            },
        }));
    };

    const applyBulkAction = () => {
        setSiteActions((current) => {
            const next = { ...current };

            selectedSiteIds.forEach((siteId) => {
                next[siteId] = {
                    ...defaultSiteAction(siteId),
                    ...current[siteId],
                    action: bulkAction,
                    scheduled_date: bulkScheduledDate,
                };
            });

            return next;
        });
    };

    const toggleItemSiteExpanded = (siteId: number) => {
        setExpandedItemSites((current) => current.includes(siteId) ? current.filter((id) => id !== siteId) : [...current, siteId]);
    };

    const selectedSummary = selectedSiteIds.length === 1
        ? `${selectedItems.length} item dipilih (${selectedGroups[0]?.siteName ?? 'Lokasi'})`
        : `${selectedItems.length} item dipilih dari ${selectedSiteIds.length} lokasi`;

    const toggleItem = (itemId: number) => {
        setSelectedIds((current) => current.includes(itemId) ? current.filter((id) => id !== itemId) : [...current, itemId]);
    };

    const toggleAll = () => {
        setSelectedIds((current) => current.length === items.length ? [] : items.map((item) => item.id));
    };

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('work-list.index'), { site_id: filterSiteId, search }, { preserveState: true, replace: true });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (selectedItems.length === 0) {
            return;
        }

        const groups = selectedGroups.map((group) => ({
            site_id: group.siteId,
            action: group.form.action,
            item_ids: group.items.map((item) => item.id),
            assigned_mechanic_id: group.form.action === 'replace' ? group.form.assigned_mechanic_id : null,
            scheduled_date: group.form.scheduled_date,
        }));

        form.setData('groups', groups);
        setShowConfirm(true);
    };

    const confirmSubmit = () => {
        form.post(route('work-list.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedIds([]);
                setSiteActions({});
                setShowConfirm(false);
            },
        });
    };

    const confirmMessage = selectedGroups.map((group) => {
        const itemText = group.items.length === 1 ? '1 item' : `${group.items.length} item`;
        return `${group.siteName}: ${itemText} — ${actionLabels[group.form.action]} pada ${group.form.scheduled_date || 'tanggal belum dipilih'}`;
    }).join('\n');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Daftar Kerja</h2>}>
            <Head title="Daftar Kerja" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <section className="rounded-xl border bg-card p-6 shadow-xs">
                        <div className="space-y-2">
                            <h3 className="text-lg font-semibold text-foreground">Daftar Kerja Planner Area</h3>
                            <p className="text-sm text-muted-foreground">Pilih beberapa item dari satu atau beberapa lokasi, lalu kirim pengajuan sekaligus. Work Orders tetap tersedia seperti biasa.</p>
                        </div>

                        <form onSubmit={applyFilters} className="mt-6 grid gap-4 md:grid-cols-[220px_1fr_auto] md:items-end">
                            <div>
                                <InputLabel htmlFor="site_id" value="Site" />
                                <Select value={filterSiteId || 'all'} onValueChange={(value) => setFilterSiteId(value === 'all' ? '' : value)}>
                                    <SelectTrigger id="site_id" className="mt-1 h-12 w-full text-base">
                                        <SelectValue placeholder="Semua Site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Site</SelectItem>
                                        {sites.data.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}
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
                                <h3 className="font-semibold text-foreground">Item aktif</h3>
                                <p className="text-sm text-muted-foreground">{items.length} item perlu dipantau. Urutan paling telat ada di atas.</p>
                            </div>
                            {selectedItems.length > 0 && <span className="rounded-full bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700">{selectedItems.length} dipilih</span>}
                        </div>

                        <div>
                            <Table className="min-w-full text-left">
                                <TableHeader className="bg-muted/50 text-muted-foreground">
                                    <TableRow>
                                        <TableHead className="w-14 px-4 py-4">
                                            <Checkbox aria-label="Pilih semua item" checked={items.length > 0 && selectedIds.length === items.length} onCheckedChange={toggleAll} />
                                        </TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Plat Nomor</TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Nama Item</TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Site</TableHead>
                                        <TableHead className="px-4 py-4 text-base font-semibold">Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody className="divide-y">
                                    {items.map((item) => (
                                        <TableRow key={item.id} className={selectedIds.includes(item.id) ? 'bg-indigo-50/50' : 'bg-card'}>
                                            <TableCell className="px-4 py-4">
                                                <Checkbox aria-label={`Pilih ${item.plate_number} ${item.item_name}`} checked={selectedIds.includes(item.id)} onCheckedChange={() => toggleItem(item.id)} />
                                            </TableCell>
                                            <TableCell className="px-4 py-4 text-base font-semibold text-foreground">{item.plate_number}</TableCell>
                                            <TableCell className="px-4 py-4 text-base text-foreground">{item.item_name}</TableCell>
                                            <TableCell className="px-4 py-4 text-base text-muted-foreground">{item.site_name}</TableCell>
                                            <TableCell className="px-4 py-4">
                                                <span className={item.status === 'overdue' ? 'rounded-full border border-red-200 bg-red-50 px-3 py-1 text-sm font-semibold text-red-700' : 'rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-sm font-semibold text-gray-700'}>
                                                    {item.status_label}
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {items.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="px-4 py-12 text-center text-base text-muted-foreground">Belum ada item aktif untuk filter ini.</TableCell>
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
                                    <p className="text-sm text-muted-foreground">Klik untuk membuka form pengajuan. Pilihan kamu tetap aman.</p>
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
                            <Button type="button" aria-label="Tutup panel pengajuan" variant="ghost" className="fixed inset-0 z-30 h-auto rounded-none bg-black/20 p-0 hover:bg-black/20" onClick={() => setIsActionPanelOpen(false)} />
                            <form onSubmit={submit} className="fixed inset-x-0 bottom-0 z-40 rounded-t-2xl border bg-background shadow-2xl">
                                <div className="mx-auto max-w-7xl">
                                    <div className="flex flex-wrap items-start justify-between gap-3 border-b p-5">
                                        <div className="space-y-2">
                                            <h3 className="text-lg font-semibold text-foreground">Kirim pengajuan</h3>
                                            {selectedSiteIds.length === 1 ? (
                                                <p className="text-sm text-muted-foreground">{selectedSummary}</p>
                                            ) : (
                                                <p className="text-sm font-medium text-indigo-700">{selectedItems.length} item dipilih, dari {selectedSiteIds.length} lokasi berbeda — diproses terpisah per lokasi.</p>
                                            )}
                                            {selectedSiteIds.length > 8 && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Kamu memilih item dari {selectedSiteIds.length} lokasi. Pertimbangkan untuk memilih lebih sedikit lokasi sekaligus agar lebih mudah diperiksa sebelum kirim.</p>}
                                            {!canSubmit && <p className="rounded-lg border border-yellow-200 bg-yellow-50 p-3 text-sm text-yellow-800">Akun ini hanya untuk supervisi. Pengiriman pengajuan dilakukan oleh Planner Area atau Superadmin.</p>}
                                        </div>
                                        <SecondaryButton type="button" onClick={() => setIsActionPanelOpen(false)}>Tutup</SecondaryButton>
                                    </div>

                                    <div className="max-h-[70vh] overflow-y-auto p-5">
                                        <div className="grid gap-4">
                                            {selectedSiteIds.length > 1 && (
                                                <div className="rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                                                    <div className="mb-3">
                                                        <h4 className="font-semibold text-indigo-900">Terapkan ke Semua Lokasi</h4>
                                                        <p className="text-sm text-indigo-700">Isi aksi dan tanggal yang sama ke semua form di bawah. Mekanik tidak ikut berubah.</p>
                                                    </div>
                                                    <div className="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                                                        <div>
                                                            <InputLabel value="Aksi" />
                                                            <Select value={bulkAction} onValueChange={(value) => setBulkAction(value as SiteAction['action'])}>
                                                                <SelectTrigger className="mt-1 h-12 w-full text-base">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="replace">Ajukan Ganti</SelectItem>
                                                                    <SelectItem value="postpone">Tunda</SelectItem>
                                                                    <SelectItem value="blocked">Blokir</SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <div>
                                                            <InputLabel value="Tanggal Rencana" />
                                                            <TextInput className="mt-1 w-full p-3 text-base" type="date" value={bulkScheduledDate} onChange={(event) => setBulkScheduledDate(event.target.value)} />
                                                        </div>
                                                        <SecondaryButton className="h-12" type="button" onClick={applyBulkAction}>Terapkan ke Semua Lokasi</SecondaryButton>
                                                    </div>
                                                </div>
                                            )}
                                            {selectedGroups.map((group) => {
                                                const isExpanded = expandedItemSites.includes(group.siteId);
                                                const visibleItems = isExpanded ? group.items : group.items.slice(0, 4);
                                                const hiddenCount = group.items.length - visibleItems.length;

                                                return (
                                                    <div key={group.siteId} className="rounded-lg border p-4">
                                                        <div className="mb-4 flex flex-wrap items-start justify-between gap-2">
                                                            <div>
                                                                <h4 className="font-semibold text-foreground">{group.siteName}</h4>
                                                                <p className="text-sm text-muted-foreground">{group.items.length} item</p>
                                                            </div>
                                                        </div>

                                                        <div className="mb-4 rounded-lg bg-muted/40 p-3">
                                                            <p className="mb-2 text-sm font-medium text-foreground">Item yang dipilih:</p>
                                                            <ul className="space-y-1 text-sm text-muted-foreground">
                                                                {visibleItems.map((item) => <li key={item.id}>{item.plate_number} — {item.item_name}</li>)}
                                                            </ul>
                                                            {hiddenCount > 0 && (
                                                                <Button type="button" variant="link" className="mt-2 h-auto p-0 text-sm font-medium text-indigo-700" onClick={() => toggleItemSiteExpanded(group.siteId)}>
                                                                    dan {hiddenCount} lainnya
                                                                </Button>
                                                            )}
                                                            {isExpanded && group.items.length > 4 && (
                                                                <Button type="button" variant="link" className="mt-2 h-auto p-0 text-sm font-medium text-indigo-700" onClick={() => toggleItemSiteExpanded(group.siteId)}>
                                                                    Tampilkan lebih sedikit
                                                                </Button>
                                                            )}
                                                        </div>

                                                        <div className="grid gap-4 md:grid-cols-3">
                                                            <div>
                                                                <InputLabel value="Aksi" />
                                                                <Select value={group.form.action} onValueChange={(value) => updateSiteAction(group.siteId, { action: value as SiteAction['action'] })}>
                                                                    <SelectTrigger className="mt-1 h-12 w-full text-base">
                                                                        <SelectValue />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="replace">Ajukan Ganti</SelectItem>
                                                                        <SelectItem value="postpone">Tunda</SelectItem>
                                                                        <SelectItem value="blocked">Blokir</SelectItem>
                                                                    </SelectContent>
                                                                </Select>
                                                            </div>
                                                            {group.form.action === 'replace' && (
                                                                <div>
                                                                    <InputLabel value="Mekanik" />
                                                                    <Select value={group.form.assigned_mechanic_id || 'none'} onValueChange={(value) => updateSiteAction(group.siteId, { assigned_mechanic_id: value === 'none' ? '' : value })}>
                                                                        <SelectTrigger className="mt-1 h-12 w-full text-base">
                                                                            <SelectValue placeholder="Pilih mekanik" />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            <SelectItem value="none">Pilih mekanik</SelectItem>
                                                                            {(mechanicsBySite[String(group.siteId)] ?? []).map((mechanic) => <SelectItem key={mechanic.id} value={String(mechanic.id)}>{mechanic.name}</SelectItem>)}
                                                                        </SelectContent>
                                                                    </Select>
                                                                </div>
                                                            )}
                                                            <div>
                                                                <InputLabel value={group.form.action === 'replace' ? 'Tanggal rencana' : 'Tanggal tindak lanjut'} />
                                                                <TextInput className="mt-1 w-full p-3 text-base" type="date" value={group.form.scheduled_date} onChange={(event) => updateSiteAction(group.siteId, { scheduled_date: event.target.value })} />
                                                            </div>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        <InputError className="mt-3" message={form.errors.groups} />
                                        <div className="mt-5 flex flex-wrap justify-end gap-3">
                                            <SecondaryButton type="button" onClick={() => setSelectedIds([])}>Batal pilih</SecondaryButton>
                                            <PrimaryButton disabled={!canSubmit || form.processing} type="submit">{selectedSiteIds.length > 1 ? `Kirim ${selectedSiteIds.length} pengajuan` : 'Kirim'}</PrimaryButton>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </>
                    )}
                </div>
            </div>

            <ConfirmDialog
                show={showConfirm}
                title="Periksa pengajuan"
                message={confirmMessage || 'Kirim pengajuan yang dipilih?'}
                confirmLabel="Kirim sekarang"
                processing={form.processing}
                onCancel={() => setShowConfirm(false)}
                onConfirm={confirmSubmit}
            />
        </AuthenticatedLayout>
    );
}
