<?php

namespace App\Console\Commands;

use App\Models\Unit;
use App\Services\UnitPlanningGenerator;
use Illuminate\Console\Command;

class BackfillUnitPlannings extends Command
{
    protected $signature = 'maintenance:backfill-unit-plannings';

    protected $description = 'Generate missing unit planning rows for existing units.';

    public function handle(UnitPlanningGenerator $generator): int
    {
        $created = 0;

        Unit::query()
            ->orderBy('id')
            ->each(function (Unit $unit) use ($generator, &$created): void {
                $created += $generator->generateForUnit($unit);
            });

        $this->info("Created {$created} unit planning rows.");

        return self::SUCCESS;
    }
}