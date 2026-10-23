<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\AccountColumnReport;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AccountColumnReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('accountColumnReport');

        if ($report instanceof AccountColumnReport) {
            return $this->user()->can('update', $report);
        }

        return $this->user()->can('create', AccountColumnReport::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $report = $this->route('accountColumnReport');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('account_column_reports', 'name')->where(
                    fn ($query) => $query->where('company_id', app(CurrentCompany::class)->id())
                )->ignore($report instanceof AccountColumnReport ? $report->id : null),
            ],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->input('name')),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = collect($this->input('account_ids'))->map(fn (mixed $id): int => (int) $id)->all();
            $found = Account::query()->whereIn('id', $ids)->count();

            if ($found !== count($ids)) {
                $validator->errors()->add('account_ids', 'Choose accounts in this company.');
            }
        });
    }
}
