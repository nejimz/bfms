<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountColumnReportRequest;
use App\Http\Resources\AccountColumnReportResource;
use App\Models\AccountColumnReport;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AccountColumnReportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AccountColumnReport::class);

        return AccountColumnReportResource::collection(
            AccountColumnReport::query()->with('accounts')->orderBy('name')->get()
        );
    }

    public function store(AccountColumnReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        $report = DB::transaction(function () use ($data) {
            $report = AccountColumnReport::query()->create([
                'company_id' => app(CurrentCompany::class)->id(),
                'name' => $data['name'],
            ]);
            $report->syncAccounts($data['account_ids']);

            return $report->load('accounts');
        });

        return (new AccountColumnReportResource($report))
            ->response()
            ->setStatusCode(201);
    }

    public function show(AccountColumnReport $accountColumnReport): AccountColumnReportResource
    {
        $this->authorize('view', $accountColumnReport);

        $accountColumnReport->load('accounts');

        return new AccountColumnReportResource($accountColumnReport);
    }

    public function update(AccountColumnReportRequest $request, AccountColumnReport $accountColumnReport): AccountColumnReportResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($accountColumnReport, $data): void {
            $accountColumnReport->update(['name' => $data['name']]);
            $accountColumnReport->syncAccounts($data['account_ids']);
        });

        return new AccountColumnReportResource($accountColumnReport->load('accounts'));
    }

    public function destroy(AccountColumnReport $accountColumnReport): Response
    {
        $this->authorize('delete', $accountColumnReport);

        $accountColumnReport->delete();

        return response()->noContent();
    }
}
