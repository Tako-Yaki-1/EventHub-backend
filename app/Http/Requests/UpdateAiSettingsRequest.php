<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSettingsRequest extends FormRequest
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
            'enabled' => ['sometimes', 'boolean'],
            'assistant_enabled' => ['sometimes', 'boolean'],
            'summary_enabled' => ['sometimes', 'boolean'],
            'daily_token_limit' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'question_max_length' => ['sometimes', 'integer', 'min:50', 'max:2000'],
        ];
    }
}
