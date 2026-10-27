<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Item::class);
    }

    protected function prepareForValidation(): void
    {
        $description = trim((string) $this->input('description', ''));

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'unit' => trim((string) $this->input('unit', '')),
            'description' => $description === '' ? null : $description,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->route('item');
        $unique = Rule::unique('items', 'name');

        if ($item instanceof Item) {
            $unique->ignore($item);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'unit' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter an item name.',
            'name.max' => 'The item name must be 255 characters or fewer.',
            'name.unique' => 'An item with this name already exists.',
            'unit.required' => 'Enter a unit.',
            'unit.max' => 'The unit must be 50 characters or fewer.',
            'description.max' => 'The description must be 1000 characters or fewer.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('name')) {
                return;
            }

            $query = Item::query()->whereRaw('lower(name) = ?', [
                Str::lower($this->string('name')->toString()),
            ]);
            $item = $this->route('item');

            if ($item instanceof Item) {
                $query->whereKeyNot($item);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', 'An item with this name already exists.');
            }
        });
    }
}
