<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ledger\JournalAlreadyPostedException;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalPoster;
use App\Domain\Ledger\JournalPostingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\JournalRequest;
use App\Http\Resources\JournalResource;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class JournalController extends Controller
{
    public function __construct(
        private readonly JournalDraft $drafts,
        private readonly JournalPoster $poster,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Journal::class);

        return JournalResource::collection(
            Journal::query()->orderByDesc('date')->orderByDesc('id')->limit(100)->get()
        );
    }

    public function store(JournalRequest $request): JsonResponse
    {
        $journal = $this->drafts->create($request->user(), $request->validated());

        return $this->journalResponse($journal, 201);
    }

    public function show(Journal $journal): JsonResponse
    {
        $this->authorize('view', $journal);

        return $this->journalResponse($journal);
    }

    public function update(JournalRequest $request, Journal $journal): JsonResponse
    {
        try {
            $journal = $this->drafts->update($journal, $request->validated());
        } catch (JournalAlreadyPostedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return $this->journalResponse($journal);
    }

    public function post(Request $request, Journal $journal): JsonResponse
    {
        $this->authorize('post', $journal);

        try {
            $journal = $this->poster->post($journal, $request->user());
        } catch (JournalAlreadyPostedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (JournalPostingException $exception) {
            throw ValidationException::withMessages([
                'lines' => $exception->getMessage(),
            ]);
        }

        return $this->journalResponse($journal);
    }

    private function journalResponse(Journal $journal, int $status = 200): JsonResponse
    {
        $journal->load('lines');

        return (new JournalResource($journal))
            ->includePreviouslyLoadedRelationships()
            ->response()
            ->setStatusCode($status);
    }
}
