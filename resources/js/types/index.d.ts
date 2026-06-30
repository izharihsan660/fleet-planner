export type UserRole =
    | 'superadmin'
    | 'planner_ho'
    | 'admin_site'
    | 'spv_ops'
    | 'logistik'
    | 'mekanik';

export interface Site {
    id: number;
    name: string;
    region: string;
    units_count?: number;
    users_count?: number;
}

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    role: UserRole;
    site_id: number | null;
    site?: Site | null;
}

export interface UnitPlateHistory {
    id: number;
    unit_id: number;
    plate_number: string;
    active_from: string;
    active_until: string | null;
}

export interface Unit {
    id: number;
    site_id: number;
    customer: string;
    current_plate: string;
    type: string;
    brand: string;
    year: number;
    current_odo: number;
    avg_km_per_day?: number | null;
    status: string;
    is_warranty: boolean;
    site?: Site;
    plate_histories?: UnitPlateHistory[];
    inspection_logs_count?: number;
}

export interface PlanningItem {
    id: number;
    name: string;
    interval_km: number;
    interval_days: number;
}

export interface UnitPlanning {
    id: number;
    unit_id: number;
    planning_item_id: number;
    last_done_km: number;
    last_done_date: string | null;
    next_due_km: number | null;
    next_due_date: string | null;
    freeze_start: string | null;
    unit?: Unit;
    planning_item?: PlanningItem;
}

export type WorkOrderStatus = 'open' | 'in_progress' | 'complete' | 'cancelled';
export type WorkOrderItemStatus = 'on_hold' | 'in_progress' | 'complete' | 'postponed' | 'blocked' | 'breakdown' | 'overdue';
export type WorkOrderItemAction = 'replace' | 'postpone' | 'blocked' | 'breakdown';

export interface WorkOrderItem {
    id: number;
    work_order_id: number;
    unit_planning_id: number;
    planning_item_id: number;
    action: WorkOrderItemAction | null;
    status: WorkOrderItemStatus;
    reason: string | null;
    notes: string | null;
    new_due_km: number | null;
    new_due_date: string | null;
    freeze_start: string | null;
    freeze_end: string | null;
    completed_odo: number | null;
    completed_date: string | null;
    approved_at: string | null;
    triggered_by_high_usage: boolean;
    planning_item?: PlanningItem;
    unit_planning?: UnitPlanning;
    submitted_by?: User | null;
    approved_by?: User | null;
}

export interface WorkOrder {
    id: number;
    unit_id: number;
    site_id: number;
    trigger_type: 'normal' | 'high_usage' | 'manual' | 'breakdown';
    status: WorkOrderStatus;
    submitted_by_id: number | null;
    approved_by_id: number | null;
    approved_at: string | null;
    notes: string | null;
    created_at: string | null;
    unit?: Unit;
    site?: Site;
    items?: WorkOrderItem[];
    items_count?: number;
    has_blocked_items?: boolean;
    has_high_usage_items?: boolean;
    submitted_by?: User | null;
    approved_by?: User | null;
}

export interface HighUsageFlag {
    id: number;
    unit_id: number;
    planning_item_id: number;
    unit_planning_id: number;
    avg_km_per_day: number;
    estimated_due_days: number;
    flagged_at: string | null;
    action_taken: 'triggered' | 'deferred' | null;
    action_taken_at: string | null;
    resolved_at: string | null;
    days_since_flagged: number;
    window: 1 | 2;
    unit?: Unit;
    planning_item?: PlanningItem;
    unit_planning?: UnitPlanning;
    action_taken_by_user?: User | null;
}

export interface ProjectionLine {
    unit_id: number;
    unit_planning_id: number;
    planning_item_id: number;
    planning_item_name: string;
    plate_number: string;
    site_id: number;
    site_name: string;
    estimated_due_date: string | null;
    estimated_due_km: number | null;
    estimated_quantity: number;
    insufficient_data: boolean;
}

export interface ProjectionUnit {
    unit_id: number;
    plate_number: string;
    site_id: number;
    site_name: string;
    current_odo: number;
    avg_km_per_day: number;
    estimated_period_odo: number;
    insufficient_data: boolean;
    items: ProjectionLine[];
}

export interface ProjectionItem {
    planning_item_id: number;
    planning_item_name: string;
    total_estimated_quantity: number;
    items: ProjectionLine[];
}

export interface ProjectionPart {
    planning_item_id: number;
    planning_item_name: string;
    total_estimated_quantity: number;
    items: ProjectionLine[];
}

export interface ProjectionWarning {
    unit_id: number;
    plate_number: string;
    site_name: string;
    inspection_count: number;
    minimum_required: number;
}

export interface ProjectionResult {
    period_months: number;
    period_end: string;
    by_unit: ProjectionUnit[];
    by_item: ProjectionItem[];
    by_part: ProjectionPart[];
    warnings: ProjectionWarning[];
}

export interface InspectionLog {
    id: number;
    unit_id: number;
    mechanic_id: number;
    inspection_date: string;
    odometer: number;
    insufficient_data: boolean;
    unit?: Unit;
    mechanic?: User;
}

export interface SystemThreshold {
    id: number;
    key: string;
    value: string;
    description: string | null;
    updated_by?: User | null;
    updated_at: string | null;
}

export interface Notification {
    id: number;
    type: string;
    title: string;
    message: string;
    data: {
        url?: string;
        work_order_id?: number;
        work_order_item_id?: number;
        unit_id?: number;
    } | null;
    read_at: string | null;
    created_at: string | null;
}

export interface ReportSummary {
    total_wo?: number;
    total_items?: number;
    total_complete?: number;
    total_overdue?: number;
    total_item?: number;
    complete?: number;
    overdue?: number;
    in_progress?: number;
    item?: string;
    avg_hari_penyelesaian?: number;
    unit_id?: number;
    plat_nomor?: string;
    site?: string;
    items?: string[];
}

export interface UnitHistoryItem {
    id: number;
    work_order_id: number;
    planning_item: string | null;
    action: WorkOrderItemAction | null;
    status: WorkOrderItemStatus;
    reason: string | null;
    notes: string | null;
    completed_odo: number | null;
    completed_date: string | null;
    new_due_km: number | null;
    new_due_date: string | null;
    submitted_by: string | null;
    created_at: string | null;
}

export interface UnitHistory {
    unit: {
        id: number;
        current_plate: string;
        site: string | null;
        customer: string;
        type: string;
        brand: string;
        year: number;
        current_odo: number;
        status: string;
    };
    replacements: UnitHistoryItem[];
    plate_histories: UnitPlateHistory[];
    blocked_breakdowns: UnitHistoryItem[];
    postpones: UnitHistoryItem[];
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};
