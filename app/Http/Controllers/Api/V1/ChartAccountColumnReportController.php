<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\ChartAccountColumnReport;
use App\Http\Controllers\Controller;
use App\Models\AccountColumnReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartAccountColumnReportController extends Controller
{
    public function __construct(private readonly ChartAccountColumnReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report' => ['required', 'integer'],
            'year' => ['nullable', 'integer', 'between:1900,9999'],
            'sort' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $columnReport = AccountColumnReport::query()->with('accounts')->find($validated['report']);

        if ($columnReport === null) {
            abort(404);
        }

        $this->authorize('view', $columnReport);

        $year = isset($validated['year']) ? (int) $validated['year'] : (int) now()->year;
        $sort = $validated['sort'] ?? 'asc';

        return response()->json($this->report->build($columnReport, $year, $sort));
    }
}
