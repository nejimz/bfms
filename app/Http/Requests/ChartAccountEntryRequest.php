<?php

namespace App\Http\Requests;

use App\Domain\Ledger\InvalidMoneyException;
use App\Domain\Ledger\Money;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
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
        return [
            'date' => ['required', 'date'],
            'account_id' => ['required', 'integer'],
            'particulars' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('particulars')) {
            $this->merge([
                'particulars' => trim((string) $this->input('particulars')),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $amount = Money::fromDecimal((string) $this->input('amount'));
            } catch (InvalidMoneyException $exception) {
                $validator->errors()->add('amount', $exception->getMessage());

                return;
            }

            if ($amount->minor <= 0) {
                $validator->errors()->add('amount', 'Enter an amount greater than zero.');
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
}
