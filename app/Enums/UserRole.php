<?php

namespace App\Enums;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case PlannerHo = 'planner_ho';
    case AdminSite = 'admin_site';
    case SpvOps = 'spv_ops';
    case Logistik = 'logistik';
    case Mekanik = 'mekanik';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            self::PlannerHo => 'Planner HO',
            self::AdminSite => 'Admin Site',
            self::SpvOps => 'SPV Ops',
            self::Logistik => 'Logistik',
            self::Mekanik => 'Mekanik',
        };
    }
}
