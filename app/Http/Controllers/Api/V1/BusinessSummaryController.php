<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\BusinessSummaryReport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessSummaryController extends Controller
{
    public function __construct(private readonly BusinessSummaryReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->seesEveryCompany(), 403, 'This dashboard is for admins and managers.');

        $validated = $request->validate([
            'period' => ['nullable', 'string', Rule::in(['month', 'year'])],
            'year' => ['nullable', 'integer', 'between:1900,9999'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $period = $validated['period'] ?? 'month';
        $year = isset($validated['year']) ? (int) $validated['year'] : (int) now()->year;
        $month = isset($validated['month']) ? (int) $validated['month'] : (int) now()->month;

        return response()->json($this->report->build($user, $period, $year, $month));
    }
}
