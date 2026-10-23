<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Company::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'currency' => strtoupper(trim((string) $this->input('currency', ''))),
            'description' => trim((string) $this->input('description', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a business name.',
            'name.max' => 'The business name must be 255 characters or fewer.',
            'description.required' => 'Enter a description.',
            'description.max' => 'The description must be 1,000 characters or fewer.',
            'currency.required' => 'Enter a currency code.',
            'currency.regex' => 'Use a 3-letter currency code, such as PHP.',
        ];
    }
}
