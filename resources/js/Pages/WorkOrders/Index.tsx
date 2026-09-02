import ConfirmDialog from '@/Components/ConfirmDialog';
import MaintenanceItemFilter from '@/Components/MaintenanceItemFilter';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import UnitFilterCombobox from '@/Components/UnitFilterCombobox';
import { BlockedItemForm, CompleteItemForm, PostponeForm, ReplaceForm } from '@/Components/WorkOrderItemActionForms';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { cn } from '@/lib/utils';
import { PageProps, PaginatedCollection, PlanningItem, Site, Unit, User, WorkOrderBoardItem, WorkOrderPreviewItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardList, Inbox, Search } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

interface ResourceCollection<T> {
    data: T[];
}

const columns = [
    { key: 'upcoming', label: 'Akan Datang' },
    { key: 'preparation', label: 'Ancang-ancang' },
    { key: 'open', label: 'Menunggu' },
    { key: 'in_progress', label: 'Sedang Dikerjakan' },
    { key: 'complete', label: 'Selesai' },
] as const;

type ColumnKey = (typeof columns)[number]['key'];

type EmptyColumnConfig = {
    title: string;
    description: string;
    action?: {
        label: string;
        targetColumn: ColumnKey;
    };
};

type BoardColumns = {
    upcoming: PaginatedCollection<WorkOrderPreviewItem>;
    preparation: PaginatedCollection<WorkOrderPreviewItem>;
    open: PaginatedCollection<WorkOrderBoardItem>;
    in_progress: PaginatedCollection<WorkOrderBoardItem>;
    complete: PaginatedCollection<WorkOrderBoardItem>;
};

const columnPageParam: Record<ColumnKey, string> = {
    upcoming: 'upcoming_page',
    preparation: 'preparation_page',
    open: 'open_page',
    in_progress: 'in_progress_page',
    complete: 'complete_page',
};

const emptyColumnConfig: Record<ColumnKey, EmptyColumnConfig> = {
    upcoming: {
        title: 'Belum ada tugas Akan Datang',
        description: 'Belum ada tugas perawatan yang masuk periode Akan Datang untuk filter ini.',
    },
    preparation: {
        title: 'Belum ada tugas Ancang-ancang',
        description: 'Tugas akan muncul saat jadwal atau KM memasuki batas ancang-ancang.',
    },
    open: {
        title: 'Belum ada pekerjaan yang perlu ditindak',
        description: 'Pekerjaan muncul di sini saat sudah waktunya dikerjakan, menunggu persetujuan, atau menunggu part.',
    },
    in_progress: {
        title: 'Belum ada pekerjaan yang sedang dikerjakan',
        description: 'Pekerjaan pindah ke sini saat mekanik sudah mulai mengerjakannya sesuai tanggal rencana.',
        action: {
            label: 'Lihat kolom Menunggu',
            targetColumn: 'open',
        },
    },
    complete: {
        title: 'Belum ada pekerjaan yang selesai',
        description: 'Pekerjaan yang sudah dituntaskan mekanik akan tercatat di sini.',
    },
};

function appendColumn<T extends { id: number }>(previous: PaginatedCollection<T>, next: PaginatedCollection<T>): PaginatedCollection<T> {
    const seenIds = new Set(previous.data.map((entry) => entry.id));

    return {
        ...next,
        data: [...previous.data, ...next.data.filter((entry) => !seenIds.has(entry.id))],
    };
}

const dueTone = {
    green: 'safe',
    yellow: 'warning',
    red: 'danger',
} as const;

function selectValue(value: string): string {
    return value || 'all';
}

function filterValue(value: string): string {
    return value === 'all' ? '' : value;
}

function AssignMechanicForm({ item, mechanics, onCancel }: { item: WorkOrderBoardItem; mechanics: User[]; onCancel: () => void }) {
    const form = useForm({
        assigned_mechanic_id: item.assigned_mechanic_id?.toString() ?? '',
        scheduled_date: item.scheduled_date ?? '',
    });
    const [showConfirm, setShowConfirm] = useState(false);
    const selectedMechanic = mechanics.find((mechanic) => mechanic.id.toString() === form.data.assigned_mechanic_id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setShowConfirm(true);
    };
    const confirm = () => form.post(route('work-orders.items.assign', [item.work_order_id, item.id]), { onSuccess: onCancel, onFinish: () => setShowConfirm(false), preserveScroll: true });

    return (
        <>
            <form onSubmit={submit} className="mt-3 space-y-3 rounded-lg border bg-muted/40 p-3">
                <Select value={selectValue(form.data.assigned_mechanic_id)} onValueChange={(value) => form.setData('assigned_mechanic_id', filterValue(value))}>
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Pilih mekanik" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Pilih mekanik</SelectItem>
                        {mechanics.map((mechanic) => (
                            <SelectItem key={mechanic.id} value={mechanic.id.toString()}>
                                {mechanic.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {form.errors.assigned_mechanic_id && <p className="text-xs text-destructive">{form.errors.assigned_mechanic_id}</p>}
                <Input type="date" value={form.data.scheduled_date} onChange={(event) => form.setData('scheduled_date', event.target.value)} />
                {form.errors.scheduled_date && <p className="text-xs text-destructive">{form.errors.scheduled_date}</p>}
                <div className="flex gap-2">
                    <PrimaryButton disabled={form.processing}>Simpan</PrimaryButton>
                    <SecondaryButton type="button" onClick={onCancel}>Batal</SecondaryButton>
                </div>
            </form>
            <ConfirmDialog show={showConfirm} message={`Jadwalkan ${item.item_name} (${item.unit_plate}) pada ${form.data.scheduled_date || '-'} dengan penanggung jawab ${selectedMechanic?.name ?? '-'}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} />
        </>
    );
}

function PreviewCard({ item, mechanics, canCreate }: { item: WorkOrderPreviewItem; mechanics: User[]; canCreate: boolean }) {
    const form = useForm({ assigned_mechanic_id: '', scheduled_date: '' });
    const [showConfirm, setShowConfirm] = useState(false);
    const [showForm, setShowForm] = useState(false);
    const siteMechanics = mechanics.filter((mechanic) => mechanic.site_id === item.site_id);
    const createTask = () => form.post(route('unit-plannings.create-work-order', item.id), { preserveScroll: true, onSuccess: () => { form.reset(); setShowForm(false); }, onFinish: () => setShowConfirm(false) });

    return (
        <Card className="gap-3 shadow-xs">
            <CardContent className="space-y-3">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="font-semibold text-foreground">{item.unit_plate}</p>
                        <p className="text-sm text-muted-foreground">{item.site_name}</p>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-2">
                        {item.is_priority && <StatusBadge tone="priority">Prioritas</StatusBadge>}
                        {item.due && <StatusBadge tone={dueTone[item.due.level]}>{item.due.label}</StatusBadge>}
                    </div>
                </div>
                <div>
                    <p className="text-sm font-medium text-foreground">{item.planning_item_name}</p>
                    <p className="mt-1 text-sm text-muted-foreground">Jatuh Tempo: {item.next_due_date ?? '-'} · KM {item.next_due_km?.toLocaleString('id-ID') ?? '-'}</p>
                </div>
                {item.approval_status === 'pending_create' && <StatusBadge tone="info">Menunggu Approval</StatusBadge>}
                {item.approval_status === 'rejected' && <StatusBadge tone="rejected">Ditolak</StatusBadge>}
                {canCreate && item.approval_status !== 'pending_create' && <PrimaryButton type="button" className="w-full text-xs normal-case" onClick={() => setShowForm(!showForm)}>Buat Tugas Sekarang</PrimaryButton>}
                {showForm && (
                    <form onSubmit={(event) => { event.preventDefault(); setShowConfirm(true); }} className="space-y-3 rounded-lg border bg-muted/40 p-3">
                        <select required value={form.data.assigned_mechanic_id} onChange={(event) => form.setData('assigned_mechanic_id', event.target.value)} className="w-full rounded-lg border-border bg-background p-2 text-sm shadow-xs focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring">
                            <option value="" disabled>Pilih mekanik penanggung jawab</option>
                            {siteMechanics.map((mechanic) => <option key={mechanic.id} value={mechanic.id}>{mechanic.name}</option>)}
                        </select>
                        {form.errors.assigned_mechanic_id && <p className="text-xs text-destructive">{form.errors.assigned_mechanic_id}</p>}
                        <Input type="date" required value={form.data.scheduled_date} onChange={(event) => form.setData('scheduled_date', event.target.value)} />
                        {form.errors.scheduled_date && <p className="text-xs text-destructive">{form.errors.scheduled_date}</p>}
                        <div className="flex gap-2">
                            <PrimaryButton disabled={form.processing}>Ajukan</PrimaryButton>
                            <SecondaryButton type="button" onClick={() => setShowForm(false)}>Batal</SecondaryButton>
                        </div>
                    </form>
                )}
            </CardContent>
            <ConfirmDialog show={showConfirm} message={`Buat Tugas Sekarang untuk ${item.unit_plate} - ${item.planning_item_name}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={createTask} />
        </Card>
    );
}

type ItemFormType = 'replace' | 'postpone' | 'blocked' | 'complete' | 'assign';

function BoardItemCard({ item, mechanics, canAssign, canSubmitActions, canCondition, activeForm, setActiveForm }: { item: WorkOrderBoardItem; mechanics: User[]; canAssign: boolean; canSubmitActions: boolean; canCondition: boolean; activeForm: { itemId: number; type: ItemFormType } | null; setActiveForm: (form: { itemId: number; type: ItemFormType } | null) => void }) {
    const isOpenForm = (type: ItemFormType) => activeForm?.itemId === item.id && activeForm.type === type;
    const toggleForm = (type: ItemFormType) => setActiveForm(isOpenForm(type) ? null : { itemId: item.id, type });
    const closeForm = () => setActiveForm(null);
    const actionsLocked = item.unit_breakdown;
    const lockedMessage = 'Unit sedang rusak. Input KM baru dan isi part yang diganti sebelum melanjutkan.';
    const canSubmitNewAction = canSubmitActions && !item.baseline_missing && ['on_hold', 'blocked', 'overdue'].includes(item.status);
    const canResubmitRejected = canSubmitActions && !item.baseline_missing && item.status === 'rejected' && ['replace', 'postpone'].includes(item.action ?? '');
    const canMarkWaitingPart = canCondition && !item.baseline_missing && ['on_hold', 'in_progress', 'overdue'].includes(item.status);
    const canComplete = canCondition && !item.baseline_missing && item.phase === 'in_progress';
    const canSchedule = canAssign && item.can_schedule;

    return (
        <Card className="gap-3 shadow-xs transition hover:border-ring/40 hover:shadow-sm">
            <CardContent className="space-y-3">
                <Link href={route('work-orders.show', item.work_order_id)} className="block">
                    <p className="truncate whitespace-nowrap text-lg font-semibold text-foreground">{item.unit_plate}</p>
                    {item.site_name && <p className="text-xs text-muted-foreground">{item.site_name}</p>}
                    <p className="mt-2 text-sm font-medium text-foreground">{item.item_name}</p>
                    <p className="mt-1 text-sm text-muted-foreground">Jatuh Tempo: {item.due_date ?? '-'} · KM {item.due_km?.toLocaleString('id-ID') ?? '-'}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {item.is_priority && <StatusBadge tone="priority">Prioritas</StatusBadge>}
                        {item.badges.map((badge) => <StatusBadge key={badge.key} tone={badge.tone}>{badge.label}</StatusBadge>)}
                    </div>
                </Link>
                {item.other_active_items_count > 0 && (
                    <p className="text-xs text-muted-foreground">Unit ini juga punya {item.other_active_items_count} item lain yang perlu ditindak</p>
                )}
                {item.baseline_missing && (
                    <Button asChild variant="secondary" className="w-full text-xs normal-case">
                        <Link href={route('work-orders.show', item.work_order_id)}>Isi data awal →</Link>
                    </Button>
                )}
                {actionsLocked && (canSubmitNewAction || canResubmitRejected || canMarkWaitingPart || canComplete) && (
                    <p className="text-xs font-medium text-destructive">{lockedMessage}</p>
                )}
                {!actionsLocked && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {canSubmitNewAction && <SecondaryButton type="button" className="flex-1 text-xs normal-case" onClick={() => toggleForm('replace')}>Ajukan Penggantian</SecondaryButton>}
                            {canSubmitNewAction && <SecondaryButton type="button" className="flex-1 text-xs normal-case" onClick={() => toggleForm('postpone')}>Ajukan Penundaan</SecondaryButton>}
                            {canResubmitRejected && item.action === 'replace' && <SecondaryButton type="button" className="flex-1 text-xs normal-case" onClick={() => toggleForm('replace')}>Ajukan Ulang Penggantian</SecondaryButton>}
                            {canResubmitRejected && item.action === 'postpone' && <SecondaryButton type="button" className="flex-1 text-xs normal-case" onClick={() => toggleForm('postpone')}>Ajukan Ulang Penundaan</SecondaryButton>}
                            {canMarkWaitingPart && item.status !== 'blocked' && <SecondaryButton type="button" className="flex-1 text-xs normal-case" onClick={() => toggleForm('blocked')}>Terhambat</SecondaryButton>}
                            {canSchedule && <SecondaryButton type="button" className="flex-1 text-xs normal-case" onClick={() => toggleForm('assign')}>{item.scheduled_date ? 'Ubah Jadwal' : 'Jadwalkan'}</SecondaryButton>}
                            {canComplete && <PrimaryButton type="button" className="flex-1 text-xs normal-case" onClick={() => toggleForm('complete')}>Selesaikan</PrimaryButton>}
                        </div>
                        {isOpenForm('replace') && <ReplaceForm workOrderId={item.work_order_id} itemId={item.id} itemName={item.item_name} defaultReason={item.reason} mechanics={mechanics.filter((mechanic) => mechanic.site_id === item.site_id)} assignedMechanicId={item.assigned_mechanic_id} scheduledDate={item.scheduled_date} onCancel={closeForm} />}
                        {isOpenForm('postpone') && <PostponeForm workOrderId={item.work_order_id} itemId={item.id} itemName={item.item_name} defaultReason={item.reason} defaultDueKm={item.new_due_km ?? item.due_km} defaultDueDate={item.new_due_date ?? item.due_date} onCancel={closeForm} />}
                        {isOpenForm('blocked') && <BlockedItemForm itemId={item.id} itemName={item.item_name} onCancel={closeForm} />}
                        {isOpenForm('complete') && <CompleteItemForm workOrderId={item.work_order_id} itemId={item.id} itemName={item.item_name} currentOdo={item.unit_current_odo} stacked onCancel={closeForm} onSuccess={closeForm} />}
                        {isOpenForm('assign') && <AssignMechanicForm item={item} mechanics={mechanics.filter((mechanic) => mechanic.site_id === item.site_id)} onCancel={closeForm} />}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function EmptyColumn({ config, onNavigate }: { config: EmptyColumnConfig; onNavigate: (column: ColumnKey) => void }) {
    const action = config.action;

    return (
        <div className="rounded-xl border border-dashed bg-background/60 p-6 text-center">
            <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-muted">
                <Inbox className="size-5 text-muted-foreground" />
            </div>
            <p className="mt-3 text-sm font-medium text-foreground">{config.title}</p>
            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">{config.description}</p>
            {action && (
                <Button type="button" variant="link" className="mt-3 h-auto p-0 text-xs" onClick={() => onNavigate(action.targetColumn)}>
                    {action.label}
                </Button>
            )}
        </div>
    );
}

export default function Index({ boardColumns, sites, units, mechanics, planningItems, filters, canCreateUpcomingTask, canAssignMechanic, canSubmitItemActions, canConditionItems }: PageProps<{ boardColumns: BoardColumns; sites: ResourceCollection<Site>; units: ResourceCollection<Unit>; mechanics: User[]; planningItems: PlanningItem[]; filters: { search: string; site_id: string; status: string; unit_id: string; assignee_id: string; planning_item_ids: number[]; include_incomplete_baseline: boolean; sort_by: 'priority' | 'due_date' | 'due_km' }; canCreateUpcomingTask: boolean; canAssignMechanic: boolean; canSubmitItemActions: boolean; canConditionItems: boolean }>) {
    const [visibleColumns, setVisibleColumns] = useState(boardColumns);
    const [search, setSearch] = useState(filters.search ?? '');
    const [siteId, setSiteId] = useState(filters.site_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [unitId, setUnitId] = useState(filters.unit_id ?? '');
    const [planningItemIds, setPlanningItemIds] = useState<number[]>(filters.planning_item_ids ?? []);
    const [assigneeId, setAssigneeId] = useState(filters.assignee_id ?? '');
    const [includeIncompleteBaseline, setIncludeIncompleteBaseline] = useState(filters.include_incomplete_baseline ?? true);
    const [sortBy, setSortBy] = useState<'priority' | 'due_date' | 'due_km'>(filters.sort_by ?? 'priority');
    const [activeForm, setActiveForm] = useState<{ itemId: number; type: ItemFormType } | null>(null);
    const [highlightedColumn, setHighlightedColumn] = useState<ColumnKey | null>(null);

    const isLoadingMore = useRef(false);
    const columnRefs = useRef<Partial<Record<ColumnKey, HTMLElement | null>>>({});
    const highlightTimer = useRef<number | null>(null);
    const requestedSearch = useRef(filters.search ?? '');
    const appliedSearch = filters.search ?? '';

    useEffect(() => {
        if (isLoadingMore.current) {
            isLoadingMore.current = false;

            return;
        }

        setVisibleColumns(boardColumns);
    }, [boardColumns]);

    useEffect(() => () => {
        if (highlightTimer.current !== null) {
            window.clearTimeout(highlightTimer.current);
        }
    }, []);

    const focusColumn = (column: ColumnKey) => {
        const element = columnRefs.current[column];

        if (!element) {
            return;
        }

        if (highlightTimer.current !== null) {
            window.clearTimeout(highlightTimer.current);
        }

        setHighlightedColumn(column);
        element.focus({ preventScroll: true });
        element.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });

        highlightTimer.current = window.setTimeout(() => {
            setHighlightedColumn(null);
            highlightTimer.current = null;
        }, 2500);
    };

    const filterQuery = (overrides: Record<string, unknown> = {}) => ({
        search: search || undefined,
        site_id: siteId || undefined,
        status: status || undefined,
        unit_id: unitId || undefined,
        assignee_id: assigneeId || undefined,
        planning_item_ids: planningItemIds.length > 0 ? planningItemIds : undefined,
        include_incomplete_baseline: includeIncompleteBaseline ? 1 : 0,
        sort_by: sortBy,
        ...overrides,
    });

    useEffect(() => {
        if (search === requestedSearch.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            requestedSearch.current = search;
            router.get(route('work-orders.index'), filterQuery(), {
                only: ['boardColumns', 'filters'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        }, 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const filter = (event: FormEvent) => {
        event.preventDefault();
        requestedSearch.current = search;
        router.get(route('work-orders.index'), filterQuery(), { preserveState: true, replace: true });
    };

    const loadMore = (column: ColumnKey) => {
        const currentColumn = visibleColumns[column];
        isLoadingMore.current = true;

        router.get(route('work-orders.index'), filterQuery({
            [columnPageParam[column]]: currentColumn.meta.current_page + 1,
        }), {
            only: ['boardColumns'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const nextColumns = page.props.boardColumns as BoardColumns;

                setVisibleColumns((previousColumns) => ({
                    ...previousColumns,
                    [column]: appendColumn(
                        previousColumns[column] as PaginatedCollection<{ id: number }>,
                        nextColumns[column] as PaginatedCollection<{ id: number }>,
                    ),
                }) as BoardColumns);
            },
            onError: () => {
                isLoadingMore.current = false;
            },
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Perintah Kerja (PK)</h2>}>
            <Head title="Perintah Kerja (PK)" />
            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <Card>
                        <CardContent>
                            <form onSubmit={filter} className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div className="relative md:col-span-2 xl:col-span-4">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        type="search"
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        aria-label="Cari unit atau item"
                                        placeholder="Cari unit atau item, misal: KT 8473 atau Service B"
                                        maxLength={100}
                                        className="pl-9"
                                    />
                                </div>
                                <Select value={selectValue(siteId)} onValueChange={(value) => setSiteId(filterValue(value))}>
                                    <SelectTrigger><SelectValue placeholder="Semua Lokasi" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Lokasi</SelectItem>
                                        {sites.data.map((site) => <SelectItem key={site.id} value={site.id.toString()}>{site.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={selectValue(status)} onValueChange={(value) => setStatus(filterValue(value))}>
                                    <SelectTrigger><SelectValue placeholder="Semua Status" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Status</SelectItem>
                                        {columns.filter((column) => !['upcoming', 'preparation'].includes(column.key)).map((column) => <SelectItem key={column.key} value={column.key}>{column.label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <UnitFilterCombobox units={units.data} value={unitId} onChange={setUnitId} />
                                <MaintenanceItemFilter items={planningItems} selectedIds={planningItemIds} onChange={setPlanningItemIds} />
                                <Select value={selectValue(assigneeId)} onValueChange={(value) => setAssigneeId(filterValue(value))}>
                                    <SelectTrigger><SelectValue placeholder="Semua Petugas" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Petugas</SelectItem>
                                        {mechanics.map((mechanic) => <SelectItem key={mechanic.id} value={mechanic.id.toString()}>{mechanic.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={sortBy} onValueChange={(value) => setSortBy(value as 'priority' | 'due_date' | 'due_km')}>
                                    <SelectTrigger><SelectValue placeholder="Urutkan berdasarkan" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="priority">Prioritas (PM Check/Service A/Service B dulu)</SelectItem>
                                        <SelectItem value="due_date">Tanggal Jatuh Tempo terdekat</SelectItem>
                                        <SelectItem value="due_km">KM Jatuh Tempo terdekat</SelectItem>
                                    </SelectContent>
                                </Select>
                                <label className="flex min-h-9 items-center gap-2 rounded-md border border-border bg-background px-3 text-sm text-foreground shadow-xs">
                                    <Checkbox checked={includeIncompleteBaseline} onCheckedChange={(checked) => setIncludeIncompleteBaseline(checked === true)} />
                                    <span>Tampilkan item dengan data awal belum lengkap</span>
                                </label>
                                <Button type="submit" className="w-full"><ClipboardList className="size-4" /> Filter</Button>
                            </form>
                        </CardContent>
                    </Card>

                    <div className="grid gap-5 lg:grid-cols-3 xl:grid-cols-5">
                        {columns.map((column) => {
                            const columnKey = column.key as ColumnKey;
                            const columnData = visibleColumns[columnKey];
                            const count = columnData.meta.total;
                            const hasMore = columnData.meta.current_page < columnData.meta.last_page;

                            return (
                                <section
                                    key={column.key}
                                    ref={(element) => {
                                        columnRefs.current[columnKey] = element;
                                    }}
                                    tabIndex={-1}
                                    aria-labelledby={`work-order-column-${column.key}`}
                                    className={cn(
                                        'scroll-mt-6 rounded-xl outline-none transition duration-500 motion-reduce:transition-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                                        highlightedColumn === columnKey && 'ring-2 ring-primary ring-offset-2 ring-offset-background',
                                    )}
                                >
                                    <Card className="h-full bg-muted/30">
                                        <CardHeader className="flex-row items-center justify-between space-y-0 pb-3">
                                            <CardTitle id={`work-order-column-${column.key}`} className="text-base">{column.label} — {count.toLocaleString('id-ID')}</CardTitle>
                                            <StatusBadge tone="neutral">{count}</StatusBadge>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            {column.key === 'upcoming' && (columnData.data as WorkOrderPreviewItem[]).map((item) => <PreviewCard key={item.id} item={item} mechanics={mechanics} canCreate={canCreateUpcomingTask} />)}
                                            {column.key === 'preparation' && (columnData.data as WorkOrderPreviewItem[]).map((item) => <PreviewCard key={item.id} item={item} mechanics={mechanics} canCreate={canCreateUpcomingTask} />)}
                                            {!['upcoming', 'preparation'].includes(column.key) && (columnData.data as WorkOrderBoardItem[]).map((item) => <BoardItemCard key={item.id} item={item} mechanics={mechanics} canAssign={canAssignMechanic} canSubmitActions={canSubmitItemActions} canCondition={canConditionItems} activeForm={activeForm} setActiveForm={setActiveForm} />)}
                                            {count === 0 && appliedSearch !== '' && <EmptyColumn config={{ title: `Tidak ada hasil untuk '${appliedSearch}'`, description: 'Coba kata kunci lain, misal sebagian plat unit atau nama item perawatan.' }} onNavigate={focusColumn} />}
                                            {count === 0 && appliedSearch === '' && <EmptyColumn config={emptyColumnConfig[columnKey]} onNavigate={focusColumn} />}
                                            {hasMore && (
                                                <Button type="button" variant="outline" className="w-full" onClick={() => loadMore(columnKey)}>
                                                    Muat Lebih Banyak
                                                </Button>
                                            )}
                                        </CardContent>
                                    </Card>
                                </section>
                            );
                        })}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
