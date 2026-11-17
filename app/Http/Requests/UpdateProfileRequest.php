<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        $studentId = $user->role === Role::Admin
            ? ['sometimes', 'nullable', 'string', 'min:3', 'max:30']
            : ['sometimes', 'required', 'string', 'min:3', 'max:30'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes', 'required', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'student_id' => [
                ...$studentId,
                Rule::unique('users', 'student_id')->ignore($user->id),
            ],
            'year_section' => ['sometimes', 'nullable', 'string', 'max:30'],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
