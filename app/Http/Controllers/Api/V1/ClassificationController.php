<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityLogger;
use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassificationRequest;
use App\Http\Requests\UpdateClassificationRequest;
use App\Models\Classification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

    public function store(StoreClassificationRequest $request, ActivityLogger $activity): JsonResponse
    {
        $classification = Classification::query()->create($request->safe()->except(['logo', 'remove_logo']));
        $this->applyLogo($classification, $request);
        $classification->save();
        $activity->record($request->user(), ActivityModule::Classification, ActivityAction::Added, $classification->name);
        $classification->load('category')->loadCount('companies');

        return response()->json([
            'data' => $this->resource($classification),
        ], 201);
    }

    public function update(UpdateClassificationRequest $request, Classification $classification, ActivityLogger $activity): JsonResponse
    {
        $classification->update($request->safe()->except(['logo', 'remove_logo']));
        $this->applyLogo($classification, $request);
        $classification->save();

        if ($classification->wasChanged()) {
            $activity->record($request->user(), ActivityModule::Classification, ActivityAction::Changed, $classification->name);
        }

        $classification->load('category')->loadCount('companies');

        return response()->json([
            'data' => $this->resource($classification),
        ]);
    }

    public function destroy(Request $request, Classification $classification, ActivityLogger $activity): Response
    {
        $this->authorize('delete', $classification);

        if ($classification->companies()->exists()) {
            throw ValidationException::withMessages([
                'classification' => 'Remove this classification from its businesses before deleting it.',
            ]);
        }

        $name = $classification->name;
        $path = $classification->logo_path;
        $classification->delete();
        $activity->record($request->user(), ActivityModule::Classification, ActivityAction::Deleted, $name);
        $classification->deleteFile(is_string($path) ? $path : null);

        return response()->noContent();
    }

    private function applyLogo(Classification $classification, StoreClassificationRequest $request): void
    {
        $file = $request->file('logo');

        if ($file instanceof UploadedFile) {
            $path = $file->store('classifications', 'public');

            if (! is_string($path)) {
                throw ValidationException::withMessages([
                    'logo' => 'The logo could not be saved.',
                ]);
            }

            $current = $classification->logo_path;
            $classification->deleteFile(is_string($current) ? $current : null);
            $classification->logo_path = $path;

            return;
        }

        if ($request->boolean('remove_logo')) {
            $current = $classification->logo_path;
            $classification->deleteFile(is_string($current) ? $current : null);
            $classification->logo_path = null;
        }
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
                'logo_url' => $classification->logoUrl(),
                'businesses_count' => (int) $classification->companies_count,
            ],
        ];
    }
}
