<?php

namespace App\Http\Requests;

use App\Models\Classification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Classification::class);
    }

    protected function prepareForValidation(): void
    {
        $fields = [
            'name' => trim((string) $this->input('name', '')),
        ];

        if ($this->exists('category_id')) {
            $categoryId = $this->input('category_id');
            $fields['category_id'] = $categoryId === '' || $categoryId === null ? null : $categoryId;
        }

        $this->merge($fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $classification = $this->route('classification');
        $unique = Rule::unique('classifications', 'name');

        if ($classification instanceof Classification) {
            $unique->ignore($classification);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a classification name.',
            'name.max' => 'The classification name must be 255 characters or fewer.',
            'name.unique' => 'A classification with this name already exists.',
            'category_id.exists' => 'Choose a category from the list.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('name')) {
                return;
            }

            $query = Classification::query()->whereRaw('lower(name) = ?', [
                Str::lower($this->string('name')->toString()),
            ]);
            $classification = $this->route('classification');

            if ($classification instanceof Classification) {
                $query->whereKeyNot($classification);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', 'A classification with this name already exists.');
            }
        });
    }
}
