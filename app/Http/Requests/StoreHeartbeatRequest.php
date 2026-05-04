<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'node_id' => ['required', 'string', 'max:120'],
            'timestamp' => ['required', 'string'],
            'overall_status' => ['required', Rule::in(config('monitoring.node_statuses'))],
            'host' => ['required', 'array'],
            'services' => ['required', 'array'],
            'services.*.name' => ['required', 'string'],
            'services.*.status' => ['required', 'string'],
            'services.*.pm_id' => ['nullable', 'integer'],
            'services.*.restart_count' => ['nullable', 'integer'],
            'services.*.cpu' => ['nullable', 'numeric'],
            'services.*.memory_mb' => ['nullable', 'numeric'],
            'problems' => ['sometimes', 'array'],
        ];
    }
}
