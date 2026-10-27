<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassificationRequest;
use App\Http\Requests\UpdateClassificationRequest;
use App\Models\Classification;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ClassificationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Classification::class);

        $classifications = Classification::query()
            ->with('category')
            ->withCount('companies')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $classifications->map(fn (Classification $classification) => $this->resource($classification))->values(),
        ]);
    }

    public function store(StoreClassificationRequest $request): JsonResponse
    {
        $classification = Classification::query()->create($request->validated());
        $classification->load('category')->loadCount('companies');

        return response()->json([
            'data' => $this->resource($classification),
        ], 201);
    }

    public function update(UpdateClassificationRequest $request, Classification $classification): JsonResponse
    {
        $classification->update($request->validated());
        $classification->load('category')->loadCount('companies');

        return response()->json([
            'data' => $this->resource($classification),
        ]);
    }

    public function destroy(Classification $classification): Response
    {
        $this->authorize('delete', $classification);

        if ($classification->companies()->exists()) {
            throw ValidationException::withMessages([
                'classification' => 'Remove this classification from its businesses before deleting it.',
            ]);
        }

        $classification->delete();

        return response()->noContent();
    }

    private function categoryId(Classification $classification): ?string
    {
        return $classification->category_id === null ? null : (string) $classification->category_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Classification $classification): array
    {
        return [
            'id' => (string) $classification->id,
            'type' => 'classifications',
            'attributes' => [
                'name' => $classification->name,
                'category_id' => $this->categoryId($classification),
                'category_name' => $classification->category?->name,
                'businesses_count' => (int) $classification->companies_count,
            ],
        ];
    }
}
