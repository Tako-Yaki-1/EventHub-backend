<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRequest extends FormRequest
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
            'days' => ['nullable', 'integer', 'min:7', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
