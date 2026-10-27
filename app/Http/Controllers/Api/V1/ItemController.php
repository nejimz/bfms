<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityLogger;
use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ItemController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Item::class);

        $items = Item::query()
            ->withCount(['lines', 'inventory'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items->map(fn (Item $item) => $this->resource($item))->values(),
        ]);
    }

    public function store(StoreItemRequest $request, ActivityLogger $activity): JsonResponse
    {
        $item = Item::query()->create($request->validated());
        $activity->record($request->user(), ActivityModule::Item, ActivityAction::Added, $item->name);
        $item->loadCount(['lines', 'inventory']);

        return response()->json([
            'data' => $this->resource($item),
        ], 201);
    }

    public function update(UpdateItemRequest $request, Item $item, ActivityLogger $activity): JsonResponse
    {
        $before = $this->snapshot($item, $activity);
        $item->update($request->validated());
        $changes = $activity->changes($before, $this->snapshot($item, $activity));

        if ($changes !== []) {
            $activity->record($request->user(), ActivityModule::Item, ActivityAction::Changed, $item->name, $changes);
        }

        $item->loadCount(['lines', 'inventory']);

        return response()->json([
            'data' => $this->resource($item),
        ]);
    }

    public function destroy(Request $request, Item $item, ActivityLogger $activity): Response
    {
        $this->authorize('delete', $item);

        if ($item->lines()->exists()) {
            throw ValidationException::withMessages([
                'item' => 'Remove this item from its entries before deleting it.',
            ]);
        }

        if ($item->inventory()->exists()) {
            throw ValidationException::withMessages([
                'item' => 'Remove this item from inventory before deleting it.',
            ]);
        }

        $name = $item->name;
        $item->delete();
        $activity->record($request->user(), ActivityModule::Item, ActivityAction::Deleted, $name);

        return response()->noContent();
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(Item $item, ActivityLogger $activity): array
    {
        return [
            'Name' => $activity->label($item->name),
            'Unit' => $activity->label($item->unit),
            'Description' => $activity->label($item->description),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Item $item): array
    {
        return [
            'id' => (string) $item->id,
            'type' => 'items',
            'attributes' => [
                'name' => $item->name,
                'unit' => $item->unit,
                'description' => $item->description,
                'entries_count' => (int) $item->lines_count,
                'inventory_count' => (int) $item->inventory_count,
            ],
        ];
    }
}
