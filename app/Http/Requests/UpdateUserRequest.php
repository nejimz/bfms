<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && ($this->user()?->can('update', $user) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $fields = [
            'name' => trim((string) $this->input('name', '')),
            'email' => strtolower(trim((string) $this->input('email', ''))),
        ];

        if ($this->input('password') === '') {
            $fields['password'] = null;
        }

        $this->merge($fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user instanceof User ? $user->id : null),
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
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
            'password.min' => 'The password must be at least 8 characters.',
            'role.required' => 'Choose a role.',
            'role.enum' => 'Choose a role.',
            'company_ids.required' => 'Choose at least one business.',
            'company_ids.min' => 'Choose at least one business.',
            'company_ids.*.exists' => 'Choose a business from the list.',
            'company_ids.*.distinct' => 'Choose each business once.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $subject = $this->route('user');

            if (! $subject instanceof User) {
                return;
            }

            $role = UserRole::from((string) $this->input('role'));
            $active = $this->exists('is_active') ? $this->boolean('is_active') : $subject->is_active;

            if ($this->user()?->is($subject) && $role !== UserRole::Admin) {
                $validator->errors()->add('role', 'Keep your own admin role.');
            }

            if ($this->user()?->is($subject) && ! $active) {
                $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
            }

            if (! $subject->leavesAnActiveAdmin($role, $active)) {
                $validator->errors()->add('role', 'Keep at least one active admin.');
            }
        });
    }
}
