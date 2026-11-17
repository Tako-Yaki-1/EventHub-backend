<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'result' => ['sometimes', 'in:success,failed,denied'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
