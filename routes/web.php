<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return ApiResponse::success(
        message: 'OK',
        data: [
            'service' => 'external-receiver',
            'timestamp' => now('UTC')->format('Y-m-d\\TH:i:s.v\\Z'),
        ],
    );
});
