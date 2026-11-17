<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListAttendeesRequest extends FormRequest
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
            'status' => ['nullable', 'in:registered,cancelled,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
