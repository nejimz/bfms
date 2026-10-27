<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\IncomeExpenseChartReport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncomeExpenseChartController extends Controller
{
    public function __construct(private readonly IncomeExpenseChartReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->seesEveryCompany(), 403, 'This chart is for admins and managers.');

        $validated = $request->validate([
            'group' => ['nullable', 'string', Rule::in(['business', 'classification'])],
        ]);

        $group = $validated['group'] ?? 'business';

        return response()->json($this->report->build($user, $group));
    }
}
