<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\ChartAccountColumnReport;
use App\Http\Controllers\Controller;
use App\Models\ChartAccountEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartAccountColumnReportController extends Controller
{
    public function __construct(private readonly ChartAccountColumnReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChartAccountEntry::class);

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:1900,9999'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'sort' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $year = isset($validated['year']) ? (int) $validated['year'] : (int) now()->year;
        $month = isset($validated['month']) ? (int) $validated['month'] : (int) now()->month;
        $sort = $validated['sort'] ?? 'asc';

        return response()->json($this->report->build($year, $month, $sort));
    }
}
