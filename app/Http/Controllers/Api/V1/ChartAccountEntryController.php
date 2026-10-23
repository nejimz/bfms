<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\ChartAccountEntryAlreadyPostedException;
use App\Domain\Ledger\Money;
use App\Enums\ChartAccountEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChartAccountEntryRequest;
use App\Http\Resources\ChartAccountEntryResource;
use App\Models\ChartAccountEntry;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ChartAccountEntryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ChartAccountEntry::class);

        $entries = ChartAccountEntry::query()
            ->with('account')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $collection = ChartAccountEntryResource::collection($entries);
        $collection->collection?->each->includePreviouslyLoadedRelationships();

        return $collection;
    }

    public function store(ChartAccountEntryRequest $request): JsonResponse
    {
        $company = app(CurrentCompany::class)->company();
        $data = $request->validated();

        $entry = ChartAccountEntry::query()->create([
            'company_id' => $company->id,
            'account_id' => $data['account_id'],
            'date' => $data['date'],
            'particulars' => $data['particulars'],
            'amount_cents' => Money::fromDecimal($data['amount'])->minor,
            'currency' => $company->currency,
            'status' => ChartAccountEntryStatus::Draft,
            'created_by' => $request->user()->id,
        ]);

        return $this->entryResponse($entry, 201);
    }

    public function show(ChartAccountEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);

        return $this->entryResponse($entry);
    }

    public function update(ChartAccountEntryRequest $request, ChartAccountEntry $entry): JsonResponse
    {
        if ($entry->isPosted()) {
            return response()->json(['message' => 'Posted chart account entries cannot be edited.'], 409);
        }

        $data = $request->validated();

        $entry->update([
            'account_id' => $data['account_id'],
            'date' => $data['date'],
            'particulars' => $data['particulars'],
            'amount_cents' => Money::fromDecimal($data['amount'])->minor,
        ]);

        return $this->entryResponse($entry);
    }

    public function destroy(ChartAccountEntry $entry): Response
    {
        $this->authorize('delete', $entry);

        if ($entry->isPosted()) {
            return response()->json(['message' => 'Posted chart account entries cannot be deleted.'], 409);
        }

        $entry->delete();

        return response()->noContent();
    }

    public function post(Request $request, ChartAccountEntry $entry): JsonResponse
    {
        $this->authorize('post', $entry);

        try {
            $entry = DB::transaction(function () use ($request, $entry) {
                /** @var ChartAccountEntry $entry */
                $entry = ChartAccountEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

                if ($entry->isPosted()) {
                    throw new ChartAccountEntryAlreadyPostedException('This chart account entry is already posted.');
                }

                $entry->forceFill([
                    'status' => ChartAccountEntryStatus::Posted,
                    'posted_at' => now(),
                    'posted_by' => $request->user()->id,
                ])->save();

                return $entry;
            });
        } catch (ChartAccountEntryAlreadyPostedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return $this->entryResponse($entry);
    }

    private function entryResponse(ChartAccountEntry $entry, int $status = 200): JsonResponse
    {
        $entry->load('account');

        return (new ChartAccountEntryResource($entry))
            ->includePreviouslyLoadedRelationships()
            ->response()
            ->setStatusCode($status);
    }
}
