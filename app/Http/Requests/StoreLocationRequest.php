<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Location::class);
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
        $location = $this->route('location');
        $unique = Rule::unique('locations', 'name');

        if ($location instanceof Location) {
            $unique->ignore($location);
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
            'name.required' => 'Enter a location name.',
            'name.max' => 'The location name must be 255 characters or fewer.',
            'name.unique' => 'A location with this name already exists.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('name')) {
                return;
            }

            $query = Location::query()->whereRaw('lower(name) = ?', [
                Str::lower($this->string('name')->toString()),
            ]);
            $location = $this->route('location');

            if ($location instanceof Location) {
                $query->whereKeyNot($location);
            }

            if ($query->exists()) {
                $validator->errors()->add('name', 'A location with this name already exists.');
            }
        });
    }
}
