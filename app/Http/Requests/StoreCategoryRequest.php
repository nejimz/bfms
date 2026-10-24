<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Category::class);
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
        $category = $this->route('category');
        $unique = Rule::unique('categories', 'name');

        if ($category instanceof Category) {
            $unique->ignore($category);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a category name.',
            'name.max' => 'The category name must be 255 characters or fewer.',
            'name.unique' => 'A category with this name already exists.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('name')) {
                return;
            }

            $query = Category::query()->whereRaw('lower(name) = ?', [
                Str::lower($this->string('name')->toString()),
            ]);
            $category = $this->route('category');

            if ($category instanceof Category) {
                $query->whereKeyNot($category);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', 'A category with this name already exists.');
            }
        });
    }
}
