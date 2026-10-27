<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Organization::current()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'icon' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_icon' => ['sometimes', 'boolean'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_banner' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a company name.',
            'name.max' => 'The company name must be 255 characters or fewer.',
            'logo.image' => 'Use a JPG, PNG, or WebP logo.',
            'logo.mimes' => 'Use a JPG, PNG, or WebP logo.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
            'icon.image' => 'Use a JPG, PNG, or WebP icon.',
            'icon.mimes' => 'Use a JPG, PNG, or WebP icon.',
            'icon.max' => 'The icon must be 2 MB or smaller.',
            'banner.image' => 'Use a JPG, PNG, or WebP banner.',
            'banner.mimes' => 'Use a JPG, PNG, or WebP banner.',
            'banner.max' => 'The banner must be 2 MB or smaller.',
        ];
    }
}
