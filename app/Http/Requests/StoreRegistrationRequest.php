<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:100'],
            'student_id' => ['required', 'string', 'max:30'],
            'year_section' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:150'],
            'confirmed_attendance' => ['required', 'accepted'],
        ];
    }
}
