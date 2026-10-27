<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityLogger;
use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function store(StoreLocationRequest $request, ActivityLogger $activity): JsonResponse
    {
        $location = Location::query()->create($request->validated());
        $activity->record($request->user(), ActivityModule::Location, ActivityAction::Added, $location->name);
        $location->loadCount('companies');

        return response()->json([
            'data' => $this->resource($location),
        ], 201);
    }

    public function update(UpdateLocationRequest $request, Location $location, ActivityLogger $activity): JsonResponse
    {
        $location->update($request->validated());

        if ($location->wasChanged()) {
            $activity->record($request->user(), ActivityModule::Location, ActivityAction::Changed, $location->name);
        }

        $location->loadCount('companies');

        return response()->json([
            'data' => $this->resource($location),
        ]);
    }

    public function destroy(Request $request, Location $location, ActivityLogger $activity): Response
    {
        $this->authorize('delete', $location);

        if ($location->companies()->exists()) {
            throw ValidationException::withMessages([
                'location' => 'Remove this location from its businesses before deleting it.',
            ]);
        }

        $name = $location->name;
        $location->delete();
        $activity->record($request->user(), ActivityModule::Location, ActivityAction::Deleted, $name);

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
