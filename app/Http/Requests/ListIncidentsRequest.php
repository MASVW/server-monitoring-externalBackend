<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'node_id' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(config('monitoring.node_statuses'))],
            'event_type' => ['nullable', Rule::in(config('monitoring.incident_event_types'))],
        ];
    }
}
