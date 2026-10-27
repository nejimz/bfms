<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityLogger;
use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()
            ->withCount('classifications')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (Category $category) => $this->resource($category))->values(),
        ]);
    }

    public function store(StoreCategoryRequest $request, ActivityLogger $activity): JsonResponse
    {
        $category = Category::query()->create($request->validated());
        $activity->record($request->user(), ActivityModule::Category, ActivityAction::Added, $category->name);
        $category->loadCount('classifications');

        return response()->json([
            'data' => $this->resource($category),
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category, ActivityLogger $activity): JsonResponse
    {
        $category->update($request->validated());

        if ($category->wasChanged()) {
            $activity->record($request->user(), ActivityModule::Category, ActivityAction::Changed, $category->name);
        }

        $category->loadCount('classifications');

        return response()->json([
            'data' => $this->resource($category),
        ]);
    }

    public function destroy(Request $request, Category $category, ActivityLogger $activity): Response
    {
        $this->authorize('delete', $category);

        if ($category->classifications()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Remove this category from its classifications before deleting it.',
            ]);
        }

        $name = $category->name;
        $category->delete();
        $activity->record($request->user(), ActivityModule::Category, ActivityAction::Deleted, $name);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Category $category): array
    {
        return [
            'id' => (string) $category->id,
            'type' => 'categories',
            'attributes' => [
                'name' => $category->name,
                'classifications_count' => (int) $category->classifications_count,
            ],
        ];
    }
}
