<?php

namespace App\Support;

use Illuminate\Support\Str;

class VehicleGroupResolver
{
    public const UNGROUPED = 'BELUM DIKELOMPOKKAN';

    public const UNKNOWN = 'TIDAK DIKETAHUI';

    /** @var array<string, string> */
    private array $groupsByType = [];

    public function __construct()
    {
        foreach (config('vehicle_groups', []) as $group => $types) {
            foreach ($types as $type) {
                $this->groupsByType[$this->normalize($type)] = $group;
            }
        }
    }

    public function resolve(?string $type): string
    {
        $normalizedType = $this->normalize($type);

        return $normalizedType === '' ? self::UNKNOWN : ($this->groupsByType[$normalizedType] ?? self::UNGROUPED);
    }

    private function normalize(?string $type): string
    {
        return Str::upper(Str::squish($type ?? ''));
    }
}
