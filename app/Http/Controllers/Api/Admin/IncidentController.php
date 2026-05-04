<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListIncidentsRequest;
use App\Services\IncidentService;
use App\Support\ApiResponse;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentService $incidentService,
    ) {}

    public function index(ListIncidentsRequest $request)
    {
        $validated = $request->validated();

        $result = $this->incidentService->getPaginatedIncidents(
            filters: [
                'node_id' => $validated['node_id'] ?? null,
                'status' => $validated['status'] ?? null,
                'event_type' => $validated['event_type'] ?? null,
            ],
            page: (int) ($validated['page'] ?? 1),
            limit: (int) ($validated['limit'] ?? 20),
        );

        return ApiResponse::success(
            data: $result['data'],
            meta: $result['meta'],
        );
    }
}
