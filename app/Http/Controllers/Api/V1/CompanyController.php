<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ledger\DefaultChart;
use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = DB::transaction(function () use ($request) {
            $company = Company::query()->create($request->validated());
            $company->users()->attach($request->user()->id, [
                'role' => CompanyRole::Owner->value,
            ]);
            DefaultChart::provision($company);

            return $company;
        });

        $this->rememberCompany($request, $company);

        return response()->json($this->companyPayload($company), 201);
    }

    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $company = $request->user()
            ->companies()
            ->where('companies.id', $data['company_id'])
            ->first();

        if ($company === null) {
            abort(403, 'You do not belong to that company.');
        }

        $this->rememberCompany($request, $company);

        return response()->json($this->companyPayload($company));
    }

    private function rememberCompany(Request $request, Company $company): void
    {
        if ($request->hasSession()) {
            $request->session()->put('current_company_id', $company->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(Company $company): array
    {
        return [
            'data' => [
                'id' => (string) $company->id,
                'type' => 'companies',
                'attributes' => [
                    'name' => $company->name,
                    'currency' => $company->currency,
                ],
            ],
        ];
    }
}
