<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()
            ->withCount('companies')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (Category $category) => $this->resource($category))->values(),
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::query()->create($request->validated());
        $category->loadCount('companies');

        return response()->json([
            'data' => $this->resource($category),
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());
        $category->loadCount('companies');

        return response()->json([
            'data' => $this->resource($category),
        ]);
    }

    public function destroy(Category $category): Response
    {
        $this->authorize('delete', $category);

        if ($category->companies()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Remove this category from its businesses before deleting it.',
            ]);
        }

        $category->delete();

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
                'businesses_count' => (int) $category->companies_count,
            ],
        ];
    }
}
