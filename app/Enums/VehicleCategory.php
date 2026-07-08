<?php

namespace App\Enums;

enum VehicleCategory: string
{
    case PickupSuv = 'pickup_suv';
    case TrukRingan = 'truk_ringan';
    case Bus = 'bus';

    public function label(): string
    {
        return match ($this) {
            self::PickupSuv => 'Pickup / SUV',
            self::TrukRingan => 'Truk Ringan',
            self::Bus => 'Bus',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
