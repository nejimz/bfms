<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Validation\Validator;

class UpdateCompanyRequest extends StoreCompanyRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && $this->user()->can('update', $company);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $company = $this->route('company');

            if (! $company instanceof Company || $validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->string('currency')->value() === $company->currency) {
                return;
            }

            $hasJournals = $company->journals()
                ->withoutGlobalScope('company')
                ->exists();

            $hasChartAccountEntries = $company->chartAccountEntries()
                ->withoutGlobalScope('company')
                ->exists();

            if ($hasJournals) {
                $validator->errors()->add(
                    'currency',
                    'Currency stays fixed once a journal exists.',
                );
            } elseif ($hasChartAccountEntries) {
                $validator->errors()->add(
                    'currency',
                    'Currency stays fixed once a chart account entry exists.',
                );
            }
        });
    }
}
