<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\ItemActivityReport;
use App\Http\Controllers\Controller;
use App\Models\ChartAccountEntry;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemActivityReportController extends Controller
{
    public function __construct(private readonly ItemActivityReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChartAccountEntry::class);

        $validated = $request->validate([
            'book' => ['required', 'string', Rule::in(array_keys(ItemActivityReport::BOOKS))],
            'grain' => ['nullable', 'string', Rule::in(['day', 'week', 'month', 'year'])],
            'year' => ['nullable', 'integer', 'between:1900,9999'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $grain = $validated['grain'] ?? 'month';
        $year = isset($validated['year']) ? (int) $validated['year'] : (int) now()->year;
        $month = isset($validated['month']) ? (int) $validated['month'] : (int) now()->month;

        return response()->json($this->report->build(
            app(CurrentCompany::class)->company(),
            $validated['book'],
            $grain,
            $year,
            $month,
        ));
    }
}
