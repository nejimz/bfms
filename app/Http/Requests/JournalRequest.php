<?php

namespace App\Http\Requests;

use App\Domain\Ledger\InvalidMoneyException;
use App\Domain\Ledger\Money;
use App\Models\Account;
use App\Models\Journal;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class JournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $journal = $this->route('journal');

        if ($journal instanceof Journal) {
            return $this->user()->can('update', $journal);
        }

        return $this->user()->can('create', Journal::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:50'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['required', 'string'],
            'lines.*.credit' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = app(CurrentCompany::class)->id();

            foreach ($this->input('lines', []) as $index => $line) {
                try {
                    $debit = Money::fromDecimal((string) ($line['debit'] ?? ''));
                    $credit = Money::fromDecimal((string) ($line['credit'] ?? ''));
                } catch (InvalidMoneyException $exception) {
                    $validator->errors()->add("lines.$index.debit", $exception->getMessage());

                    continue;
                }

                if ($debit->minor > 0 && $credit->minor > 0) {
                    $validator->errors()->add("lines.$index.debit", 'Enter a debit or a credit, not both.');
                } elseif ($debit->minor === 0 && $credit->minor === 0) {
                    $validator->errors()->add("lines.$index.debit", 'Enter a debit or a credit.');
                }

                $account = Account::query()->find($line['account_id'] ?? null);

                if ($account === null || $account->company_id !== $companyId) {
                    $validator->errors()->add("lines.$index.account_id", 'Choose an account in this company.');
                } elseif (! $account->is_active) {
                    $validator->errors()->add("lines.$index.account_id", 'That account is inactive.');
                }
            }
        });
    }
}
