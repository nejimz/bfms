<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ChartAccount\YearlySummaryReport;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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

    public function days(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->seesEveryCompany(), 403, 'This summary is for admins and managers.');

        $validated = $request->validate([
            'business' => ['required', 'integer'],
            'year' => ['required', 'integer', 'between:1900,9999'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $company = $user->accessibleCompanies()->whereKey($validated['business'])->first();

        abort_unless($company instanceof Company, 404);

        return response()->json($this->report->days(
            $company,
            (int) $validated['year'],
            (int) $validated['month'],
        ));
    }

    public function records(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->seesEveryCompany(), 403, 'This summary is for admins and managers.');

        $validated = $request->validate([
            'business' => ['required', 'integer'],
            'year' => ['required', 'integer', 'between:1900,9999'],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $day = (int) $validated['day'];

        if ($day > Carbon::create($year, $month, 1)->daysInMonth) {
            throw ValidationException::withMessages([
                'day' => 'That day is not in this month.',
            ]);
        }

        $company = $user->accessibleCompanies()->whereKey($validated['business'])->first();

        abort_unless($company instanceof Company, 404);

        return response()->json($this->report->records($company, $year, $month, $day));
    }
}
