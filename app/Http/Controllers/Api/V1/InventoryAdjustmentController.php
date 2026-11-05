<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityLogger;
use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryAdjustmentRequest;
use App\Models\InventoryAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InventoryAdjustmentController extends Controller
{
    public function store(StoreInventoryAdjustmentRequest $request, ActivityLogger $activity): JsonResponse
    {
        $adjustment = InventoryAdjustment::query()->create($request->validated());
        $adjustment->load('item');
        $activity->record(
            $request->user(),
            ActivityModule::Inventory,
            ActivityAction::Added,
            $adjustment->item?->name ?? 'Item',
            [
                ['field' => 'Date', 'from' => 'None', 'to' => $adjustment->date->toDateString()],
                ['field' => 'Adjustment', 'from' => 'None', 'to' => $adjustment->quantityLabel()],
            ],
        );

        return response()->json([
            'data' => $this->resource($adjustment),
        ], 201);
    }

    public function destroy(Request $request, InventoryAdjustment $inventoryAdjustment, ActivityLogger $activity): Response
    {
        $this->authorize('delete', $inventoryAdjustment);

        $inventoryAdjustment->loadMissing('item');
        $name = $inventoryAdjustment->item?->name ?? 'Item';
        $inventoryAdjustment->delete();
        $activity->record($request->user(), ActivityModule::Inventory, ActivityAction::Deleted, $name);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(InventoryAdjustment $adjustment): array
    {
        $adjustment->loadMissing('item');

        return [
            'id' => (string) $adjustment->id,
            'type' => 'inventory-adjustments',
            'attributes' => [
                'item_id' => (string) $adjustment->item_id,
                'item_name' => $adjustment->item?->name,
                'date' => $adjustment->date?->toDateString(),
                'quantity' => $adjustment->quantityLabel(),
                'note' => $adjustment->note,
            ],
        ];
    }
}
