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
        if ($this->isInternalHeartbeat()) {
            return [
                'type' => ['required', 'string'],
                'generatedAt' => ['required', 'string'],
                'node' => ['required', 'string', 'max:120'],
                'summary' => ['required', 'array'],
                'summary.overallStatus' => ['required', 'string'],
                'services' => ['required', 'array'],
                'services.*.name' => ['required', 'string'],
                'services.*.status' => ['sometimes', 'string'],
                'services.*.healthStatus' => ['sometimes', 'string'],
                'hostMetrics' => ['required', 'array'],
                'connectivity' => ['sometimes', 'array'],
                'incidents' => ['sometimes', 'array'],
            ];
        }

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

    private function isInternalHeartbeat(): bool
    {
        return (string) $this->header('x-heartbeat-signature', '') !== ''
            || $this->hasAny(['type', 'generatedAt', 'node']);
    }
}
