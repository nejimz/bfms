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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ChartAccountEntryController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ChartAccountEntry::class);

        $validated = $request->validate([
            'account' => [
                'nullable',
                'string',
                'max:20',
                Rule::exists('accounts', 'code')->where('company_id', app(CurrentCompany::class)->id()),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $entries = ChartAccountEntry::query()
            ->with(['account', 'lines'])
            ->when(isset($validated['account']), function (Builder $query) use ($validated): void {
                $query->whereHas('account', function (Builder $account) use ($validated): void {
                    $account->where('code', $validated['account']);
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id');

        if (! isset($validated['account'])) {
            return $this->entryCollection($entries->get());
        }

        $drafts = (clone $entries)->where('status', ChartAccountEntryStatus::Draft)->count();
        $collection = $this->entryCollection($entries->paginate(self::PER_PAGE)->withQueryString());
        $collection->additional([
            'meta' => [
                'drafts' => $drafts,
            ],
        ]);

        return $collection;
    }

    public function store(ChartAccountEntryRequest $request): JsonResponse
    {
        $company = app(CurrentCompany::class)->company();
        $data = $request->validated();

        $entry = DB::transaction(function () use ($company, $data, $request) {
            $entry = ChartAccountEntry::query()->create([
                'company_id' => $company->id,
                'account_id' => $data['account_id'],
                'date' => $data['date'],
                'particulars' => $data['particulars'],
                'reference' => $data['reference'] ?? null,
                'amount_cents' => $this->amountMinor($data),
                'cash_cents' => $this->settlementMinor($data, 'cash'),
                'credit_cents' => $this->settlementMinor($data, 'credit'),
                'currency' => $company->currency,
                'status' => ChartAccountEntryStatus::Draft,
                'created_by' => $request->user()->id,
            ]);

            if (array_key_exists('lines', $data)) {
                $entry->replaceLines($data['lines']);
            }

            return $entry;
        });

        return $this->entryResponse($entry, 201);
    }

    public function show(ChartAccountEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);

        return $this->entryResponse($entry);
    }

    public function update(ChartAccountEntryRequest $request, ChartAccountEntry $entry): JsonResponse
    {
        $data = $request->validated();

        if ($entry->isPosted()) {
            $entry->update([
                'reference' => array_key_exists('reference', $data) ? $data['reference'] : $entry->reference,
                'cash_cents' => $this->settlementMinor($data, 'cash'),
                'credit_cents' => $this->settlementMinor($data, 'credit'),
            ]);

            return $this->entryResponse($entry);
        }

        DB::transaction(function () use ($data, $entry) {
            $entry->update([
                'account_id' => $data['account_id'],
                'date' => $data['date'],
                'particulars' => $data['particulars'],
                'reference' => $data['reference'] ?? null,
                'amount_cents' => $this->amountMinor($data),
                'cash_cents' => $this->settlementMinor($data, 'cash'),
                'credit_cents' => $this->settlementMinor($data, 'credit'),
            ]);

            if (array_key_exists('lines', $data)) {
                $entry->replaceLines($data['lines']);
            }
        });

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

    /**
     * @param  array<string, mixed>  $data
     */
    private function amountMinor(array $data): int
    {
        if (! array_key_exists('lines', $data)) {
            return Money::fromDecimal((string) $data['amount'])->minor;
        }

        /** @var list<array{amount: string}> $lines */
        $lines = $data['lines'];
        $total = Money::zero();

        foreach ($lines as $line) {
            $total = $total->plus(Money::fromDecimal($line['amount']));
        }

        return $total->minor;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function settlementMinor(array $data, string $field): int
    {
        return Money::fromDecimal((string) $data[$field])->minor;
    }

    /**
     * @param  Collection<int, ChartAccountEntry>|LengthAwarePaginator<int, ChartAccountEntry>  $entries
     */
    private function entryCollection(Collection|LengthAwarePaginator $entries): AnonymousResourceCollection
    {
        $collection = ChartAccountEntryResource::collection($entries);
        $collection->collection?->each->includePreviouslyLoadedRelationships();

        return $collection;
    }

    private function entryResponse(ChartAccountEntry $entry, int $status = 200): JsonResponse
    {
        $entry->load(['account', 'lines']);

        return (new ChartAccountEntryResource($entry))
            ->includePreviouslyLoadedRelationships()
            ->response()
            ->setStatusCode($status);
    }
}
