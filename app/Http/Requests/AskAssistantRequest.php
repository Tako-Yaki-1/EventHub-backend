<?php

namespace App\Http\Requests;

use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Http\FormRequest;

class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $question = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $this->input('question', ''));

        $this->merge(['question' => trim((string) $question)]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $max = app(AiSettings::class)->questionMaxLength();

        return [
            'question' => ['required', 'string', "max:{$max}"],
        ];
    }
}
