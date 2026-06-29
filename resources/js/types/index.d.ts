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
    status: string;
    is_warranty: boolean;
    site?: Site;
    plate_histories?: UnitPlateHistory[];
}

export interface PlanningItem {
    id: number;
    name: string;
    interval_km: number;
    interval_days: number;
}

export interface SystemThreshold {
    id: number;
    key: string;
    value: string;
    description: string | null;
    updated_by?: User | null;
    updated_at: string | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};
