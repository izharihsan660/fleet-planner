<?php

namespace App\Exports;

use App\Models\Unit;
use App\Support\VehicleGroupResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\BeforeWriting;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ProjectionExport extends DefaultValueBinder implements WithCustomValueBinder, WithEvents, WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $projection
     * @param  Collection<int, Unit>  $units
     */
    public function __construct(
        private readonly array $projection,
        private readonly Collection $units,
        private readonly CarbonImmutable $periodStart,
        private readonly string $filterLabel,
    ) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [BeforeWriting::class => fn (BeforeWriting $event) => $event->writer->setDefaultValueBinder()];
    }

    /**
     * @return array<int, ProjectionSummarySheet|ProjectionDetailSheet>
     */
    public function sheets(): array
    {
        $projectedUnits = collect($this->projection['by_unit']);
        $resolver = new VehicleGroupResolver;
        $vehicleGroups = $this->units->map(fn (Unit $unit): string => $resolver->resolve($unit->type));

        $items = $projectedUnits
            ->flatMap(fn (array $unit): array => $unit['items'])
            ->unique(fn (array $item): string => $item['unit_id'].':'.$item['planning_item_id'])
            ->map(function (array $item) use ($vehicleGroups): array {
                $unit = $this->units->get($item['unit_id']);
                $vehicleGroup = $vehicleGroups->get($item['unit_id'], VehicleGroupResolver::UNKNOWN);
                $dueDate = $item['estimated_due_date'] !== null
                    ? CarbonImmutable::parse($item['estimated_due_date'])
                    : null;
                $overdue = $dueDate?->lessThan($this->periodStart) ?? false;

                return [
                    ...$item,
                    'vehicle_group' => $vehicleGroup,
                    'vehicle_group_order' => match ($vehicleGroup) {
                        VehicleGroupResolver::UNGROUPED => 1,
                        VehicleGroupResolver::UNKNOWN => 2,
                        default => 0,
                    },
                    'unit_type' => $unit?->type,
                    'region_name' => $unit?->site?->area?->name ?? $unit?->site?->region ?? '-',
                    'bucket' => $dueDate === null ? 'undated' : ($overdue ? 'overdue' : $dueDate->format('Y-m')),
                    'bucket_label' => $dueDate === null ? 'Tanpa Tanggal' : ($overdue ? 'Lewat Due' : $dueDate->locale('id')->isoFormat('MMM YYYY')),
                    'days_late' => $overdue ? (int) $dueDate->diffInDays($this->periodStart) : null,
                ];
            })
            ->sortBy([
                ['vehicle_group_order', 'asc'],
                ['vehicle_group', 'asc'],
                ['plate_number', 'asc'],
                ['estimated_due_date', 'asc'],
            ])
            ->values();

        $periodEnd = CarbonImmutable::parse($this->projection['period_end']);

        return [
            new ProjectionSummarySheet($items, $this->calendarMonths($periodEnd), [
                'Periode: '.$this->periodStart->locale('id')->isoFormat('D MMM YYYY')." \u{2013} ".$periodEnd->locale('id')->isoFormat('D MMM YYYY').' | Filter: '.$this->filterLabel,
                $this->projection['excluded_date_without_source_count'].' item tidak diproyeksikan (tanggal due tanpa data terakhir ganti)',
                "Angka per item = jumlah unit. Subtotal = jumlah kebutuhan (unit \u{00D7} item).",
            ]),
            new ProjectionDetailSheet($items),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function calendarMonths(CarbonImmutable $periodEnd): array
    {
        $months = [];

        for ($month = $this->periodStart->startOfMonth(); $month->lessThanOrEqualTo($periodEnd); $month = $month->addMonth()) {
            $start = $month->max($this->periodStart);
            $end = $month->endOfMonth()->startOfDay()->min($periodEnd);
            $label = $month->locale('id')->isoFormat('MMM YYYY');

            if ($start->greaterThan($month)) {
                $label .= ' ('.$start->locale('id')->isoFormat('D MMM');
                $label .= $start->isSameDay($end) ? ')' : ' - '.$end->locale('id')->isoFormat('D MMM').')';
            } elseif ($end->lessThan($month->endOfMonth()->startOfDay())) {
                $label .= ' (s/d '.$end->locale('id')->isoFormat('D MMM').')';
            }

            $months[$month->format('Y-m')] = $label;
        }

        return $months;
    }
}
