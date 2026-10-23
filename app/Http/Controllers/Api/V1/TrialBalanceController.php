<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ledger\TrialBalanceReport;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TrialBalanceController extends Controller
{
    public function __construct(private readonly TrialBalanceReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Journal::class);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfMonth();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->startOfDay()
            : now()->endOfMonth();

        return response()->json($this->report->build($from, $to));
    }
}
