<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\StockCardReport;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockCardReportController extends Controller
{
    public function __construct(private readonly StockCardReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Inventory::class);

        $validated = $request->validate([
            'item_id' => ['sometimes', 'integer', 'exists:items,id'],
        ], [
            'item_id.integer' => 'Choose an item.',
            'item_id.exists' => 'Choose an item.',
        ]);

        $itemId = isset($validated['item_id']) ? (int) $validated['item_id'] : null;

        return response()->json($this->report->build($itemId));
    }
}
