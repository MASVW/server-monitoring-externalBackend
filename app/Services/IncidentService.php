<?php

namespace App\Services;

use App\Models\IncidentEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class IncidentService
{
    public function createIncident(array $attributes): IncidentEvent
    {
        return IncidentEvent::create([
            'node_id' => $attributes['node_id'],
            'from_status' => $attributes['from_status'] ?? null,
            'to_status' => $attributes['to_status'],
            'event_type' => $attributes['event_type'],
            'message' => $attributes['message'],
            'metadata_json' => $attributes['metadata_json'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now('UTC'),
        ]);
    }

    public function getPaginatedIncidents(array $filters, int $page, int $limit): array
    {
        $query = IncidentEvent::query();

        if (! empty($filters['node_id'])) {
            $query->where('node_id', $filters['node_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('to_status', $filters['status']);
        }

        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        $paginator = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ];
    }
}
