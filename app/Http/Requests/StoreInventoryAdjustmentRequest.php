<?php

namespace App\Http\Requests;

use App\Models\InventoryAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryAdjustment::class);
    }

    protected function prepareForValidation(): void
    {
        $note = trim((string) $this->input('note', ''));
        $quantity = trim((string) $this->input('quantity', ''));

        if (str_starts_with($quantity, '-.')) {
            $quantity = '-0'.substr($quantity, 1);
        } elseif (str_starts_with($quantity, '.')) {
            $quantity = '0'.$quantity;
        }

        $this->merge([
            'quantity' => $quantity,
            'note' => $note === '' ? null : $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'date' => ['required', 'date'],
            'quantity' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'item_id.required' => 'Choose an item.',
            'item_id.integer' => 'Choose an item.',
            'item_id.exists' => 'Choose an item.',
            'date.required' => 'Enter the date.',
            'date.date' => 'Enter the date.',
            'quantity.required' => 'Enter a quantity other than zero, with up to 3 decimal places.',
            'note.max' => 'The note must be 1000 characters or fewer.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('quantity')) {
                return;
            }

            $quantity = $this->normalizeQuantity((string) $this->input('quantity', ''));

            if ($quantity === null) {
                $validator->errors()->add('quantity', 'Enter a quantity other than zero, with up to 3 decimal places.');

                return;
            }

            if (bccomp(ltrim($quantity, '-'), '999999999.999', 3) === 1) {
                $validator->errors()->add('quantity', 'Enter a quantity of at most 999,999,999.999.');

                return;
            }

            if (bccomp($quantity, '0', 3) === 0) {
                $validator->errors()->add('quantity', 'Enter a quantity other than zero, with up to 3 decimal places.');

                return;
            }

            $data = $validator->getData();
            $data['quantity'] = $quantity;
            $validator->setData($data);
        });
    }

    private function normalizeQuantity(string $quantity): ?string
    {
        if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,3})?$/', $quantity)) {
            return null;
        }

        $negative = str_starts_with($quantity, '-');
        $normalized = bcadd(ltrim($quantity, '-'), '0', 3);

        return $negative ? '-'.$normalized : $normalized;
    }
}
