<?php

namespace App\Enums;

enum ManualProjectionReason: string
{
    case Aus = 'aus';
    case Rusak = 'rusak';
    case TemuanMekanik = 'temuan_mekanik';
    case KeluhanDriver = 'keluhan_driver';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Aus => 'Aus',
            self::Rusak => 'Rusak',
            self::TemuanMekanik => 'Temuan Mekanik',
            self::KeluhanDriver => 'Keluhan Driver',
            self::Lainnya => 'Lainnya',
        };
    }
}
