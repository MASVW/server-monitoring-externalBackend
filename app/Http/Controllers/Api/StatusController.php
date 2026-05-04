<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatusService;
use App\Support\ApiResponse;

class StatusController extends Controller
{
    public function __construct(
        private readonly StatusService $statusService,
    ) {}

    public function show(string $nodeId)
    {
        $status = $this->statusService->getNodeStatus($nodeId);

        if ($status === null) {
            return ApiResponse::error('Node not found', 'Not Found', 404);
        }

        return ApiResponse::success(data: $status);
    }
}
