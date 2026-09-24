<?php

namespace App\Console\Commands;

use App\Models\WorkOrderItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CleanupDuplicateWorkOrderItems extends Command
{
    /**
     * @var list<string>
     */
    private const DELETABLE_STATUSES = ['rejected', 'cancelled'];

    protected $signature = 'wo:cleanup-duplicate-items
                            {--execute : Hapus baris rejected/cancelled yang memiliki baris aktif pengganti}';

    protected $description = 'Preview or delete duplicate rejected/cancelled work order items when an active row exists.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));

        if (! $execute) {
            $analysis = $this->analyzeDuplicateGroups($this->duplicateItems());

            $this->displayAnalysis($analysis);
            $this->info('DRY-RUN selesai. Tidak ada perubahan database. Jalankan kembali dengan --execute untuk menghapus kandidat.');
            $this->displaySummary($analysis, 0, false);

            return self::SUCCESS;
        }

        try {
            $result = DB::transaction(function (): array {
                $analysis = $this->analyzeDuplicateGroups($this->duplicateItems(lockForUpdate: true));

                return [
                    'analysis' => $analysis,
                    'deleted_count' => $this->deleteCandidates($analysis['processed']),
                ];
            });
        } catch (Throwable $exception) {
            $this->error('Cleanup gagal. Seluruh penghapusan telah di-rollback: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->displayAnalysis($result['analysis']);
        $this->info('EXECUTE selesai.');
        $this->displaySummary($result['analysis'], $result['deleted_count'], true);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WorkOrderItem>
     */
    private function duplicateItems(bool $lockForUpdate = false): Collection
    {
        $table = (new WorkOrderItem)->getTable();
        $query = WorkOrderItem::query()
            ->with([
                'workOrder.unit:id,current_plate',
                'planningItem:id,name',
            ])
            ->whereExists(function (QueryBuilder $duplicateQuery) use ($table): void {
                $duplicateQuery
                    ->selectRaw('1')
                    ->from($table.' as duplicate_items')
                    ->whereColumn('duplicate_items.work_order_id', $table.'.work_order_id')
                    ->whereColumn('duplicate_items.planning_item_id', $table.'.planning_item_id')
                    ->groupBy([
                        'duplicate_items.work_order_id',
                        'duplicate_items.planning_item_id',
                    ])
                    ->havingRaw('COUNT(*) > 1');
            })
            ->orderBy($table.'.work_order_id')
            ->orderBy($table.'.planning_item_id')
            ->orderBy($table.'.id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return array{
     *     processed: SupportCollection<int, array{
     *         work_order_id: int,
     *         planning_item_id: int,
     *         unit_plate: string,
     *         planning_item: string,
     *         candidates: Collection<int, WorkOrderItem>,
     *         retained: Collection<int, WorkOrderItem>
     *     }>,
     *     skipped: SupportCollection<int, array{
     *         work_order_id: int,
     *         planning_item_id: int,
     *         unit_plate: string,
     *         planning_item: string,
     *         item_ids: string,
     *         reason: string
     *     }>
     * }
     */
    private function analyzeDuplicateGroups(Collection $items): array
    {
        $processed = collect();
        $skipped = collect();

        $items
            ->groupBy(fn (WorkOrderItem $item): string => $item->work_order_id.':'.$item->planning_item_id)
            ->each(function (Collection $groupItems) use ($processed, $skipped): void {
                $firstItem = $groupItems->first();
                $candidates = $groupItems
                    ->filter(fn (WorkOrderItem $item): bool => in_array($item->status, self::DELETABLE_STATUSES, true))
                    ->values();
                $retained = $groupItems
                    ->reject(fn (WorkOrderItem $item): bool => in_array($item->status, self::DELETABLE_STATUSES, true))
                    ->values();

                if ($candidates->isNotEmpty() && $retained->isNotEmpty()) {
                    $processed->push([
                        'work_order_id' => (int) $firstItem->work_order_id,
                        'planning_item_id' => (int) $firstItem->planning_item_id,
                        'unit_plate' => $firstItem->workOrder?->unit?->current_plate ?? '-',
                        'planning_item' => $firstItem->planningItem?->name ?? '-',
                        'candidates' => $candidates,
                        'retained' => $retained,
                    ]);

                    return;
                }

                $skipped->push([
                    'work_order_id' => (int) $firstItem->work_order_id,
                    'planning_item_id' => (int) $firstItem->planning_item_id,
                    'unit_plate' => $firstItem->workOrder?->unit?->current_plate ?? '-',
                    'planning_item' => $firstItem->planningItem?->name ?? '-',
                    'item_ids' => $groupItems->pluck('id')->implode(', '),
                    'reason' => $retained->isEmpty()
                        ? 'grup dilewati: tidak ada baris aktif'
                        : 'grup dilewati: tidak ada baris rejected/cancelled',
                ]);
            });

        return compact('processed', 'skipped');
    }

    /**
     * @param  SupportCollection<int, array{
     *     candidates: Collection<int, WorkOrderItem>,
     *     retained: Collection<int, WorkOrderItem>
     * }>  $processedGroups
     */
    private function deleteCandidates(SupportCollection $processedGroups): int
    {
        $deletedCount = 0;

        foreach ($processedGroups as $group) {
            foreach ($group['candidates'] as $item) {
                Log::warning('Menghapus duplicate work order item lama.', [
                    'work_order_id' => (int) $item->work_order_id,
                    'planning_item_id' => (int) $item->planning_item_id,
                    'work_order_item_id' => (int) $item->id,
                    'row_json' => json_encode(
                        $item->getAttributes(),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                ]);

                if (! $item->delete()) {
                    throw new RuntimeException('Gagal menghapus work_order_item ID '.$item->id.'.');
                }

                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /**
     * @param  array{
     *     processed: SupportCollection<int, array{
     *         work_order_id: int,
     *         planning_item_id: int,
     *         unit_plate: string,
     *         planning_item: string,
     *         candidates: Collection<int, WorkOrderItem>,
     *         retained: Collection<int, WorkOrderItem>
     *     }>,
     *     skipped: SupportCollection<int, array{
     *         work_order_id: int,
     *         planning_item_id: int,
     *         unit_plate: string,
     *         planning_item: string,
     *         item_ids: string,
     *         reason: string
     *     }>
     * }  $analysis
     */
    private function displayAnalysis(array $analysis): void
    {
        $rows = $analysis['processed']->flatMap(function (array $group): array {
            $retainedIds = $group['retained']->pluck('id')->implode(', ');

            return $group['candidates']->map(fn (WorkOrderItem $item): array => [
                $group['work_order_id'],
                $group['unit_plate'],
                $group['planning_item'],
                $item->id,
                $item->status,
                $item->created_at?->toDateTimeString() ?? '-',
                $retainedIds,
            ])->all();
        });

        if ($rows->isEmpty()) {
            $this->info('Tidak ada baris rejected/cancelled yang memenuhi syarat untuk dihapus.');
        } else {
            $this->table(
                ['WO ID', 'Plat Unit', 'Planning Item', 'ID Akan Dihapus', 'Status', 'Created At', 'ID Dipertahankan'],
                $rows->all(),
            );
        }

        if ($analysis['skipped']->isNotEmpty()) {
            $this->warn('Grup duplikat yang dilewati:');
            $this->table(
                ['WO ID', 'Plat Unit', 'Planning Item', 'ID Baris', 'Alasan'],
                $analysis['skipped']->map(fn (array $group): array => [
                    $group['work_order_id'],
                    $group['unit_plate'],
                    $group['planning_item'],
                    $group['item_ids'],
                    $group['reason'],
                ])->all(),
            );
        }
    }

    /**
     * @param  array{
     *     processed: SupportCollection<int, array{candidates: Collection<int, WorkOrderItem>}>,
     *     skipped: SupportCollection<int, array{reason: string}>
     * }  $analysis
     */
    private function displaySummary(array $analysis, int $deletedCount, bool $executed): void
    {
        $candidateCount = (int) $analysis['processed']
            ->sum(fn (array $group): int => $group['candidates']->count());

        $this->newLine();
        $this->info(($executed ? 'Total dihapus: ' : 'Total akan dihapus: ').($executed ? $deletedCount : $candidateCount));
        $this->info('Grup diproses: '.$analysis['processed']->count());
        $this->info('Grup di-skip: '.$analysis['skipped']->count());

        $analysis['skipped']
            ->groupBy('reason')
            ->each(fn (SupportCollection $groups, string $reason) => $this->warn('- '.$reason.': '.$groups->count()));
    }
}
