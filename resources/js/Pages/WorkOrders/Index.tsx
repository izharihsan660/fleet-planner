import ConfirmDialog from '@/Components/ConfirmDialog';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, PlanningItem, Site, Unit, User, WorkOrder, WorkOrderPreviewItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardList, Inbox } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface ResourceCollection<T> {
    data: T[];
}

const columns = [
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'preparation', label: 'Ancang-ancang' },
    { key: 'open', label: 'On Hold' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'complete', label: 'Complete' },
] as const;

type ColumnKey = (typeof columns)[number]['key'];

type BoardColumns = {
    upcoming: PaginatedCollection<WorkOrderPreviewItem>;
    preparation: PaginatedCollection<WorkOrderPreviewItem>;
    open: PaginatedCollection<WorkOrder>;
    in_progress: PaginatedCollection<WorkOrder>;
    complete: PaginatedCollection<WorkOrder>;
};

const columnPageParam: Record<ColumnKey, string> = {
    upcoming: 'upcoming_page',
    preparation: 'preparation_page',
    open: 'open_page',
    in_progress: 'in_progress_page',
    complete: 'complete_page',
};

const dueTone = {
    green: 'safe',
    yellow: 'warning',
    red: 'danger',
} as const;

function itemSummary(names: string[] = []): string {
    if (names.length <= 2) {
        return names.join(', ') || '-';
    }

    return `${names.slice(0, 2).join(', ')} +${names.length - 2} lainnya`;
}

function selectValue(value: string): string {
    return value || 'all';
}

function filterValue(value: string): string {
    return value === 'all' ? '' : value;
}

function AssignMechanicForm({ workOrder, mechanics, onCancel }: { workOrder: WorkOrder; mechanics: User[]; onCancel: () => void }) {
    const form = useForm({
        assigned_mechanic_id: workOrder.assigned_mechanic_id?.toString() ?? '',
        scheduled_date: workOrder.scheduled_date ?? '',
    });
    const [showConfirm, setShowConfirm] = useState(false);
    const selectedMechanic = mechanics.find((mechanic) => mechanic.id.toString() === form.data.assigned_mechanic_id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setShowConfirm(true);
    };
    const confirm = () => form.post(route('work-orders.assign-mechanic', workOrder.id), { onSuccess: onCancel, onFinish: () => setShowConfirm(false), preserveScroll: true });

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
            <ConfirmDialog show={showConfirm} message={`Assign mekanik ${selectedMechanic?.name ?? '-'} ke WO #${workOrder.id} pada ${form.data.scheduled_date || '-'}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={confirm} />
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
                    {item.due && <StatusBadge tone={dueTone[item.due.level]}>{item.due.label}</StatusBadge>}
                </div>
                <div>
                    <p className="text-sm font-medium text-foreground">{item.planning_item_name}</p>
                    <p className="mt-1 text-sm text-muted-foreground">Due: {item.next_due_date ?? '-'} · KM {item.next_due_km?.toLocaleString('id-ID') ?? '-'}</p>
                </div>
                {item.approval_status === 'pending_create' && <StatusBadge tone="info">Menunggu Approval</StatusBadge>}
                {item.approval_status === 'rejected' && <StatusBadge tone="rejected">Rejected</StatusBadge>}
                {canCreate && item.approval_status !== 'pending_create' && <PrimaryButton type="button" className="w-full text-xs normal-case" onClick={() => setShowForm(!showForm)}>Buat Task Sekarang</PrimaryButton>}
                {showForm && (
                    <form onSubmit={(event) => { event.preventDefault(); setShowConfirm(true); }} className="space-y-3 rounded-lg border bg-muted/40 p-3">
                        <select value={form.data.assigned_mechanic_id} onChange={(event) => form.setData('assigned_mechanic_id', event.target.value)} className="w-full rounded-lg border-border bg-background p-2 text-sm shadow-xs focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring">
                            <option value="">Mekanik belum ditentukan</option>
                            {siteMechanics.map((mechanic) => <option key={mechanic.id} value={mechanic.id}>{mechanic.name}</option>)}
                        </select>
                        {form.errors.assigned_mechanic_id && <p className="text-xs text-destructive">{form.errors.assigned_mechanic_id}</p>}
                        <Input type="date" min={new Date().toISOString().slice(0, 10)} value={form.data.scheduled_date} onChange={(event) => form.setData('scheduled_date', event.target.value)} />
                        {form.errors.scheduled_date && <p className="text-xs text-destructive">{form.errors.scheduled_date}</p>}
                        <div className="flex gap-2">
                            <PrimaryButton disabled={form.processing}>Ajukan</PrimaryButton>
                            <SecondaryButton type="button" onClick={() => setShowForm(false)}>Batal</SecondaryButton>
                        </div>
                    </form>
                )}
            </CardContent>
            <ConfirmDialog show={showConfirm} message={`Buat Task Sekarang untuk ${item.unit_plate} - ${item.planning_item_name}?`} processing={form.processing} onCancel={() => setShowConfirm(false)} onConfirm={createTask} />
        </Card>
    );
}

function WorkOrderProgress({ workOrder }: { workOrder: WorkOrder }) {
    const totalItems = workOrder.items_count ?? workOrder.items?.length ?? 0;
    const completedItems = workOrder.completed_items_count ?? 0;
    const percentage = totalItems > 0 ? Math.round((completedItems / totalItems) * 100) : 0;

    if (totalItems <= 1) {
        return null;
    }

    return (
        <div className="space-y-1.5">
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${percentage}%` }} />
            </div>
            <p className="text-xs text-muted-foreground">{completedItems} dari {totalItems} tuntas</p>
        </div>
    );
}

function WorkOrderCard({ workOrder, mechanics, canAssign, canReview, canApprove, assignId, setAssignId }: { workOrder: WorkOrder; mechanics: User[]; canAssign: boolean; canReview: boolean; canApprove: boolean; assignId: number | null; setAssignId: (id: number | null) => void }) {
    const totalItems = workOrder.items_count ?? workOrder.items?.length ?? 0;
    const visibleItemCount = Math.max(totalItems, 1);
    const remainingItems = workOrder.remaining_items_count ?? totalItems;
    const isWaitingApproval = workOrder.sub_status?.key === 'waiting_approval';
    const isWaitingPart = workOrder.sub_status?.key === 'waiting_part';
    const hasAssignedMechanic = workOrder.assigned_mechanic_id !== null;
    const canShowAssign = canAssign && workOrder.status === 'in_progress' && !isWaitingApproval && !isWaitingPart && !hasAssignedMechanic;
    const canShowReview = canReview && workOrder.status === 'open';
    const canShowApproval = canApprove && isWaitingApproval;
    const canShowCompletion = workOrder.status === 'in_progress' && hasAssignedMechanic && !isWaitingApproval && !isWaitingPart;

    return (
        <Card className="gap-3 shadow-xs transition hover:border-ring/40 hover:shadow-sm">
            <CardContent className="space-y-3">
                <Link href={route('work-orders.show', workOrder.id)} className="block">
                    <div className="flex flex-wrap items-start gap-2">
                        <div className="min-w-0 flex-1 basis-36">
                            <p className="truncate whitespace-nowrap font-semibold text-foreground">{workOrder.unit?.current_plate}</p>
                            <p className="text-sm text-muted-foreground">WO #{workOrder.id} · {workOrder.site?.name}</p>
                        </div>
                        {workOrder.nearest_due && <div className="shrink-0 basis-full sm:basis-auto"><StatusBadge tone={dueTone[workOrder.nearest_due.level]}>{workOrder.nearest_due.label}</StatusBadge></div>}
                    </div>
                    <p className="mt-3 text-sm font-medium text-foreground">{itemSummary(workOrder.planning_item_names)}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"><span>{visibleItemCount} item · {workOrder.trigger_type}</span>{workOrder.trigger_type === 'manual' && <StatusBadge tone="blocked">Temuan Manual</StatusBadge>}</div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {workOrder.sub_status && workOrder.sub_status.key !== 'assigned' && <StatusBadge tone="neutral">{workOrder.sub_status.label}</StatusBadge>}
                        {workOrder.has_high_usage_items && <StatusBadge tone="highUsage">High Usage</StatusBadge>}
                        {workOrder.has_overdue_items && <StatusBadge tone="danger">Overdue</StatusBadge>}
                        {workOrder.has_blocked_items && <StatusBadge tone="blocked">Blocked</StatusBadge>}
                        {workOrder.has_rejected_items && <StatusBadge tone="rejected">Rejected</StatusBadge>}
                    </div>
                </Link>
                <WorkOrderProgress workOrder={workOrder} />
                {hasAssignedMechanic && workOrder.assigned_mechanic && (
                    <p className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap rounded-md bg-muted px-3 py-2 text-xs font-medium text-muted-foreground" title={workOrder.assigned_mechanic.name}>
                        Mekanik: {workOrder.assigned_mechanic.name}
                    </p>
                )}
                {canShowReview && (
                    <Button asChild variant="secondary" className="w-full text-xs normal-case">
                        <Link href={route('work-orders.show', workOrder.id)}>Tinjau &amp; Tindak Lanjuti</Link>
                    </Button>
                )}
                {canShowApproval && (
                    <div className="grid grid-cols-2 gap-2">
                        <Button type="button" className="text-xs normal-case" onClick={() => router.post(route('work-orders.approve', workOrder.id), {}, { preserveScroll: true })}>Approve</Button>
                        <Button type="button" variant="destructive" className="text-xs normal-case" onClick={() => router.post(route('work-orders.reject', workOrder.id), {}, { preserveScroll: true })}>Reject</Button>
                    </div>
                )}
                {canShowAssign && (
                    <div>
                        <SecondaryButton type="button" className="w-full text-xs normal-case" onClick={() => setAssignId(assignId === workOrder.id ? null : workOrder.id)}>
                            Assign Mekanik
                        </SecondaryButton>
                        {assignId === workOrder.id && <AssignMechanicForm workOrder={workOrder} mechanics={mechanics} onCancel={() => setAssignId(null)} />}
                    </div>
                )}
                {canShowCompletion && (
                    <Button asChild className="h-auto min-h-8 w-full whitespace-normal px-3 py-2 text-center text-xs leading-snug normal-case">
                        <Link href={route('work-orders.show', workOrder.id)}>
                            {totalItems <= 1 ? 'Complete' : `Lihat & Selesaikan (${remainingItems} tersisa) →`}
                        </Link>
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function EmptyColumn() {
    return (
        <div className="rounded-xl border border-dashed bg-background/60 p-6 text-center">
            <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-muted">
                <Inbox className="size-5 text-muted-foreground" />
            </div>
            <p className="mt-3 text-sm font-medium text-foreground">Belum ada item</p>
            <p className="mt-1 text-xs text-muted-foreground">Task akan muncul otomatis saat memenuhi filter.</p>
        </div>
    );
}

export default function Index({ boardColumns, sites, units, mechanics, planningItems, filters, canCreateUpcomingTask, canAssignMechanic, canReviewWorkOrders, canApproveWorkOrders }: PageProps<{ boardColumns: BoardColumns; sites: ResourceCollection<Site>; units: ResourceCollection<Unit>; mechanics: User[]; planningItems: PlanningItem[]; filters: { site_id?: string; status?: string; unit_id?: string; item_id?: string; assignee_id?: string }; canCreateUpcomingTask: boolean; canAssignMechanic: boolean; canReviewWorkOrders: boolean; canApproveWorkOrders: boolean }>) {
    const [visibleColumns, setVisibleColumns] = useState(boardColumns);
    const [siteId, setSiteId] = useState(filters.site_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [unitId, setUnitId] = useState(filters.unit_id ?? '');
    const [itemId, setItemId] = useState(filters.item_id ?? '');
    const [assigneeId, setAssigneeId] = useState(filters.assignee_id ?? '');
    const [assignId, setAssignId] = useState<number | null>(null);

    useEffect(() => {
        setVisibleColumns(boardColumns);
    }, [boardColumns]);

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('work-orders.index'), { site_id: siteId || undefined, status: status || undefined, unit_id: unitId || undefined, item_id: itemId || undefined, assignee_id: assigneeId || undefined }, { preserveState: true });
    };

    const loadMore = (column: ColumnKey) => {
        const currentColumn = visibleColumns[column];

        router.get(route('work-orders.index'), {
            site_id: siteId || undefined,
            status: status || undefined,
            unit_id: unitId || undefined,
            item_id: itemId || undefined,
            assignee_id: assigneeId || undefined,
            [columnPageParam[column]]: currentColumn.meta.current_page + 1,
        }, {
            only: ['boardColumns'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const nextColumns = page.props.boardColumns as BoardColumns;

                setVisibleColumns((previousColumns) => ({
                    ...previousColumns,
                    [column]: {
                        ...nextColumns[column],
                        data: [...previousColumns[column].data, ...nextColumns[column].data],
                    },
                }));
            },
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Work Orders</h2>}>
            <Head title="Work Orders" />
            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <Card>
                        <CardContent>
                            <form onSubmit={filter} className="grid gap-3 md:grid-cols-6">
                                <Select value={selectValue(siteId)} onValueChange={(value) => setSiteId(filterValue(value))}>
                                    <SelectTrigger><SelectValue placeholder="Semua Site" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Site</SelectItem>
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
                                <Select value={selectValue(unitId)} onValueChange={(value) => setUnitId(filterValue(value))}>
                                    <SelectTrigger><SelectValue placeholder="Semua Unit" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Unit</SelectItem>
                                        {units.data.map((unit) => <SelectItem key={unit.id} value={unit.id.toString()}>{unit.current_plate}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={selectValue(itemId)} onValueChange={(value) => setItemId(filterValue(value))}>
                                    <SelectTrigger><SelectValue placeholder="Semua Item" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Item</SelectItem>
                                        {planningItems.map((item) => <SelectItem key={item.id} value={item.id.toString()}>{item.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={selectValue(assigneeId)} onValueChange={(value) => setAssigneeId(filterValue(value))}>
                                    <SelectTrigger><SelectValue placeholder="Semua Assignee" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua Assignee</SelectItem>
                                        {mechanics.map((mechanic) => <SelectItem key={mechanic.id} value={mechanic.id.toString()}>{mechanic.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
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
                                <Card key={column.key} className="bg-muted/30">
                                    <CardHeader className="flex-row items-center justify-between space-y-0 pb-3">
                                        <CardTitle className="text-base">{column.label} — {count.toLocaleString('id-ID')}</CardTitle>
                                        <StatusBadge tone="neutral">{count}</StatusBadge>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {column.key === 'upcoming' && (columnData.data as WorkOrderPreviewItem[]).map((item) => <PreviewCard key={item.id} item={item} mechanics={mechanics} canCreate={canCreateUpcomingTask} />)}
                                        {column.key === 'preparation' && (columnData.data as WorkOrderPreviewItem[]).map((item) => <PreviewCard key={item.id} item={item} mechanics={mechanics} canCreate={canCreateUpcomingTask} />)}
                                        {!['upcoming', 'preparation'].includes(column.key) && (columnData.data as WorkOrder[]).map((workOrder) => <WorkOrderCard key={workOrder.id} workOrder={workOrder} mechanics={mechanics} canAssign={canAssignMechanic} canReview={canReviewWorkOrders} canApprove={canApproveWorkOrders} assignId={assignId} setAssignId={setAssignId} />)}
                                        {count === 0 && <EmptyColumn />}
                                        {hasMore && (
                                            <Button type="button" variant="outline" className="w-full" onClick={() => loadMore(columnKey)}>
                                                Muat Lebih Banyak
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
