<?php

namespace App\Enums;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case Mekanik = 'mekanik';
    case PlannerArea = 'planner_area';
    case SpvHo = 'spv_ho';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            self::Mekanik => 'Mekanik',
            self::PlannerArea => 'Planner Area',
            self::SpvHo => 'Spv HO',
        };
    }
}
