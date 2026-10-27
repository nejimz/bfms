<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Location::class);

        $locations = Location::query()
            ->withCount('companies')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $locations->map(fn (Location $location) => $this->resource($location))->values(),
        ]);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = Location::query()->create($request->validated());
        $location->loadCount('companies');

        return response()->json([
            'data' => $this->resource($location),
        ], 201);
    }

    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        $location->update($request->validated());
        $location->loadCount('companies');

        return response()->json([
            'data' => $this->resource($location),
        ]);
    }

    public function destroy(Location $location): Response
    {
        $this->authorize('delete', $location);

        if ($location->companies()->exists()) {
            throw ValidationException::withMessages([
                'location' => 'Remove this location from its businesses before deleting it.',
            ]);
        }

        $location->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Location $location): array
    {
        return [
            'id' => (string) $location->id,
            'type' => 'locations',
            'attributes' => [
                'name' => $location->name,
                'businesses_count' => (int) $location->companies_count,
            ],
        ];
    }
}
