<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'email' => strtolower(trim((string) $this->input('email', ''))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'company_ids' => [
                Rule::excludeIf(fn () => $this->input('role') !== UserRole::Staff->value),
                'required',
                'array',
                'min:1',
            ],
            'company_ids.*' => [
                Rule::excludeIf(fn () => $this->input('role') !== UserRole::Staff->value),
                'integer',
                'distinct',
                Rule::exists('companies', 'id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name.',
            'name.max' => 'The name must be 255 characters or fewer.',
            'email.required' => 'Enter an email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'That email address is already used.',
            'password.required' => 'Enter a password.',
            'password.min' => 'The password must be at least 8 characters.',
            'role.required' => 'Choose a role.',
            'role.enum' => 'Choose a role.',
            'company_ids.required' => 'Choose at least one business.',
            'company_ids.min' => 'Choose at least one business.',
            'company_ids.*.exists' => 'Choose a business from the list.',
            'company_ids.*.distinct' => 'Choose each business once.',
        ];
    }
}
