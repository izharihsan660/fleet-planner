<?php

namespace App\Console\Commands;

use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\SystemThreshold;
use Database\Seeders\PlanningItemSeeder;
use Database\Seeders\RegionSiteSeeder;
use Database\Seeders\SystemThresholdSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedProductionMasterData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'production:seed-master-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed production-safe Regions, Sites, Planning Items, and System Thresholds only';

    /**
     * Execute the console command.
     */
    public function handle(
        RegionSiteSeeder $regionSiteSeeder,
        PlanningItemSeeder $planningItemSeeder,
        SystemThresholdSeeder $systemThresholdSeeder,
    ): int {
        DB::transaction(function () use ($regionSiteSeeder, $planningItemSeeder, $systemThresholdSeeder): void {
            $regionSiteSeeder->run();
            $planningItemSeeder->seedPlanningItems();
            $systemThresholdSeeder->run();
        });

        $this->components->info('Master data production berhasil disinkronkan tanpa data demo.');
        $this->table(
            ['Master Data', 'Jumlah'],
            [
                ['Regions', Region::query()->count()],
                ['Sites', Site::query()->count()],
                ['Planning Items', PlanningItem::query()->count()],
                ['System Thresholds', SystemThreshold::query()->count()],
            ],
        );

        return self::SUCCESS;
    }
}
