<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ledger\DefaultChart;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $companies = $request->user()
            ->accessibleCompanies()
            ->with(['category', 'location'])
            ->withCount([
                'accounts' => fn ($query) => $query->withoutGlobalScope('company'),
                'journals' => fn ($query) => $query->withoutGlobalScope('company'),
            ])
            ->orderBy('companies.name')
            ->get();

        return response()->json([
            'data' => $companies->map(fn (Company $company) => [
                'id' => (string) $company->id,
                'type' => 'companies',
                'attributes' => [
                    'name' => $company->name,
                    'description' => $company->description,
                    'currency' => $company->currency,
                    'category_id' => $this->categoryId($company),
                    'category_name' => $company->category?->name,
                    'location_id' => $this->locationId($company),
                    'location_name' => $company->location?->name,
                    'logo_url' => $company->logoUrl(),
                    'accounts_count' => $company->accounts_count,
                    'journals_count' => $company->journals_count,
                ],
            ])->values(),
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = DB::transaction(function () use ($request) {
            $company = Company::query()->create($request->safe()->except(['logo', 'remove_logo']));
            DefaultChart::provision($company);
            $this->applyLogo($company, $request);
            $company->save();

            return $company;
        });

        $this->rememberCompany($request, $company);

        return response()->json($this->companyPayload($company), 201);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->safe()->except(['logo', 'remove_logo']));
        $this->applyLogo($company, $request);
        $company->save();

        return response()->json($this->companyPayload($company));
    }

    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $company = $request->user()
            ->accessibleCompanies()
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
        $company->load(['category', 'location']);

        return [
            'data' => [
                'id' => (string) $company->id,
                'type' => 'companies',
                'attributes' => [
                    'name' => $company->name,
                    'description' => $company->description,
                    'currency' => $company->currency,
                    'category_id' => $this->categoryId($company),
                    'category_name' => $company->category?->name,
                    'location_id' => $this->locationId($company),
                    'location_name' => $company->location?->name,
                    'logo_url' => $company->logoUrl(),
                ],
            ],
        ];
    }

    private function applyLogo(Company $company, StoreCompanyRequest $request): void
    {
        $file = $request->file('logo');

        if ($file instanceof UploadedFile) {
            $path = $file->store('companies', 'public');

            if (! is_string($path)) {
                throw ValidationException::withMessages([
                    'logo' => 'The logo could not be saved.',
                ]);
            }

            $current = $company->logo_path;
            $company->deleteFile(is_string($current) ? $current : null);
            $company->logo_path = $path;

            return;
        }

        if ($request->boolean('remove_logo')) {
            $current = $company->logo_path;
            $company->deleteFile(is_string($current) ? $current : null);
            $company->logo_path = null;
        }
    }

    private function categoryId(Company $company): ?string
    {
        return $company->category_id === null ? null : (string) $company->category_id;
    }

    private function locationId(Company $company): ?string
    {
        return $company->location_id === null ? null : (string) $company->location_id;
    }
}
