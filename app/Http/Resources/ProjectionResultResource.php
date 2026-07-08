<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProjectionResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'period_months' => $this->resource['period_months'],
            'period_end' => $this->resource['period_end'],
            'by_unit' => $this->paginateArray($request, $this->resource['by_unit'], 'unit_page'),
            'by_item' => $this->paginateArray($request, $this->resource['by_item'], 'item_page'),
            'by_part' => $this->paginateArray($request, $this->resource['by_part'], 'part_page'),
            'warnings' => $this->resource['warnings'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function paginateArray(Request $request, array $items, string $pageName): array
    {
        $perPage = 25;
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);
        $collection = Collection::make($items);
        $paginator = new LengthAwarePaginator(
            $collection->forPage($page, $perPage)->values(),
            $collection->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $pageName]
        );

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'links' => $paginator->linkCollection()->toArray(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
