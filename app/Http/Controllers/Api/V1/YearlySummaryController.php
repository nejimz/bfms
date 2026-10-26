<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\YearlySummaryReport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YearlySummaryController extends Controller
{
    public function __construct(private readonly YearlySummaryReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->seesEveryCompany(), 403, 'This summary is for admins and managers.');

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:1900,9999'],
        ]);

        $year = isset($validated['year']) ? (int) $validated['year'] : (int) now()->year;

        return response()->json($this->report->build($user, $year));
    }
}
