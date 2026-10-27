<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityLogger;
use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Inventory::class);

        $inventories = Inventory::query()
            ->select('inventories.*')
            ->join('items', 'items.id', '=', 'inventories.item_id')
            ->with('item')
            ->orderBy('items.name')
            ->orderBy('inventories.id')
            ->get();

        return response()->json([
            'data' => $inventories->map(fn (Inventory $inventory): array => $this->resource($inventory))->values(),
        ]);
    }

    public function store(StoreInventoryRequest $request, ActivityLogger $activity): JsonResponse
    {
        $inventory = Inventory::query()->create($request->validated());
        $inventory->load('item');
        $activity->record($request->user(), ActivityModule::Inventory, ActivityAction::Added, $inventory->recordName());

        return response()->json([
            'data' => $this->resource($inventory),
        ], 201);
    }

    public function update(UpdateInventoryRequest $request, Inventory $inventory, ActivityLogger $activity): JsonResponse
    {
        $before = $this->snapshot($inventory, $activity);
        $inventory->update($request->validated());
        $inventory->unsetRelation('item');
        $changes = $activity->changes($before, $this->snapshot($inventory, $activity));

        if ($changes !== []) {
            $activity->record($request->user(), ActivityModule::Inventory, ActivityAction::Changed, $inventory->recordName(), $changes);
        }

        return response()->json([
            'data' => $this->resource($inventory),
        ]);
    }

    public function destroy(Request $request, Inventory $inventory, ActivityLogger $activity): Response
    {
        $this->authorize('delete', $inventory);

        $name = $inventory->recordName();
        $inventory->delete();
        $activity->record($request->user(), ActivityModule::Inventory, ActivityAction::Deleted, $name);

        return response()->noContent();
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(Inventory $inventory, ActivityLogger $activity): array
    {
        $inventory->loadMissing('item');

        return [
            'Item' => $activity->label($inventory->item?->name),
            'Quantity' => $inventory->quantityLabel(),
            'Note' => $activity->label($inventory->note),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Inventory $inventory): array
    {
        $inventory->loadMissing('item');

        return [
            'id' => (string) $inventory->id,
            'type' => 'inventories',
            'attributes' => [
                'item_id' => (string) $inventory->item_id,
                'item_name' => $inventory->item?->name,
                'unit' => $inventory->item?->unit,
                'quantity' => $inventory->quantityLabel(),
                'note' => $inventory->note,
            ],
        ];
    }
}
