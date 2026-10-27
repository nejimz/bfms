<?php

namespace App\Http\Requests;

use App\Domain\Ledger\InvalidMoneyException;
use App\Domain\Ledger\Money;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ChartAccountEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        if ($entry instanceof ChartAccountEntry) {
            return $this->user()->can('update', $entry);
        }

        return $this->user()->can('create', ChartAccountEntry::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entry = $this->route('entry');

        if ($entry instanceof ChartAccountEntry && $entry->isPosted()) {
            return [
                'reference' => ['sometimes', 'nullable', 'string', 'max:50'],
                'cash' => ['required', 'string'],
                'credit' => ['required', 'string'],
            ];
        }

        return [
            'date' => ['required', 'date'],
            'account_id' => ['required', 'integer'],
            'particulars' => ['required', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:50'],
            'amount' => ['exclude_with:lines', 'required_without:lines', 'string'],
            'cash' => ['required', 'string'],
            'credit' => ['required', 'string'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:30'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'lines.*.quantity' => ['required', 'string'],
            'lines.*.amount' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required_without' => 'The amount field is required.',
            'reference.max' => 'Enter a reference number of at most 50 characters.',
            'cash.required' => 'Enter the cash amount.',
            'credit.required' => 'Enter the credit amount.',
            'lines.min' => 'Add an item.',
            'lines.max' => 'An entry can have at most 30 items.',
            'lines.*.item_id.required' => 'Choose an item.',
            'lines.*.item_id.integer' => 'Choose an item.',
            'lines.*.item_id.exists' => 'Choose an item.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $entry = $this->route('entry');

        if ($entry instanceof ChartAccountEntry && $entry->isPosted() && $this->hasAny(['date', 'account_id', 'particulars', 'amount', 'lines'])) {
            throw new HttpResponseException(response()->json([
                'message' => 'Posted chart account entries cannot be edited.',
            ], 409));
        }

        if ($this->has('particulars')) {
            $this->merge([
                'particulars' => trim((string) $this->input('particulars')),
            ]);
        }

        if ($this->exists('reference')) {
            $reference = trim((string) $this->input('reference'));

            $this->merge([
                'reference' => $reference === '' ? null : $reference,
            ]);
        }

        if (! is_array($this->input('lines'))) {
            return;
        }

        $lines = [];

        foreach ($this->input('lines') as $line) {
            if (! is_array($line)) {
                $lines[] = $line;

                continue;
            }

            $lines[] = $line;
        }

        $this->merge(['lines' => $lines]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $entry = $this->route('entry');

            if ($entry instanceof ChartAccountEntry && $entry->isPosted()) {
                $this->validateSettlement($validator, (int) $entry->amount_cents);

                return;
            }

            if ($this->exists('lines')) {
                $this->validateLines($validator);
            } else {
                $this->validateHeaderAmount($validator);
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $totalMinor = $this->exists('lines')
                ? $this->linesTotalMinor()
                : Money::fromDecimal((string) $this->input('amount'))->minor;

            $this->validateSettlement($validator, $totalMinor);

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = app(CurrentCompany::class)->id();
            $account = Account::query()->find($this->input('account_id'));

            if ($account === null || $account->company_id !== $companyId) {
                $validator->errors()->add('account_id', 'Choose an account in this company.');
            } elseif (! $account->is_active) {
                $validator->errors()->add('account_id', 'That account is inactive.');
            }
        });
    }

    private function validateSettlement(Validator $validator, int $totalMinor): void
    {
        $cash = $this->parseMoney($validator, 'cash');
        $credit = $this->parseMoney($validator, 'credit');

        if ($cash === null || $credit === null) {
            return;
        }

        try {
            $settled = $cash->plus($credit);
        } catch (InvalidMoneyException $exception) {
            $validator->errors()->add('cash', $exception->getMessage());

            return;
        }

        if ($settled->minor !== $totalMinor) {
            $validator->errors()->add('cash', 'Cash and credit must equal the total.');
        }
    }

    private function parseMoney(Validator $validator, string $field): ?Money
    {
        try {
            return Money::fromDecimal((string) $this->input($field));
        } catch (InvalidMoneyException $exception) {
            $validator->errors()->add($field, $exception->getMessage());

            return null;
        }
    }

    private function linesTotalMinor(): int
    {
        $total = Money::zero();

        foreach ($this->input('lines') as $line) {
            $total = $total->plus(Money::fromDecimal((string) $line['amount']));
        }

        return $total->minor;
    }

    private function validateHeaderAmount(Validator $validator): void
    {
        try {
            $amount = Money::fromDecimal((string) $this->input('amount'));
        } catch (InvalidMoneyException $exception) {
            $validator->errors()->add('amount', $exception->getMessage());

            return;
        }

        if ($amount->minor <= 0) {
            $validator->errors()->add('amount', 'Enter an amount greater than zero.');
        }
    }

    private function validateLines(Validator $validator): void
    {
        $normalized = [];

        foreach ($this->input('lines') as $index => $line) {
            if (! is_array($line)) {
                $validator->errors()->add("lines.$index", 'Enter the item.');

                continue;
            }

            $quantity = $this->normalizeQuantity((string) ($line['quantity'] ?? ''));

            if ($quantity === null) {
                $validator->errors()->add("lines.$index.quantity", 'Enter a quantity with up to 3 decimal places.');
            } elseif (bccomp($quantity, '0', 3) !== 1) {
                $validator->errors()->add("lines.$index.quantity", 'Enter a quantity greater than zero.');
            }

            try {
                $amount = Money::fromDecimal((string) ($line['amount'] ?? ''));
            } catch (InvalidMoneyException $exception) {
                $validator->errors()->add("lines.$index.amount", $exception->getMessage());

                continue;
            }

            if ($amount->minor <= 0) {
                $validator->errors()->add("lines.$index.amount", 'Enter an amount greater than zero.');

                continue;
            }

            if ($quantity === null || bccomp($quantity, '0', 3) !== 1) {
                continue;
            }

            $normalized[] = [
                'item_id' => (int) $line['item_id'],
                'quantity' => $quantity,
                'amount' => $amount->toDecimal(),
            ];
        }

        if ($validator->errors()->isEmpty()) {
            $this->merge(['lines' => $normalized]);
        }
    }

    private function normalizeQuantity(string $quantity): ?string
    {
        $quantity = trim($quantity);

        if (str_starts_with($quantity, '.')) {
            $quantity = '0'.$quantity;
        }

        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/', $quantity)) {
            return null;
        }

        return bcadd($quantity, '0', 3);
    }
}
