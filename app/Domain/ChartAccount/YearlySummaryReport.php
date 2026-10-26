<?php

namespace App\Domain\ChartAccount;

use App\Domain\Ledger\Money;
use App\Enums\ChartAccountEntryStatus;
use App\Models\Category;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class YearlySummaryReport
{
    /**
     * Posted sales, operation expenses, and purchase expenses for one calendar year,
     * with every category and the businesses under it.
     *
     * @return array{
     *     data: list<array{
     *         id: string|null,
     *         name: string,
     *         businesses: list<array{
     *             id: string,
     *             name: string,
     *             currency: string,
     *             sales: string,
     *             operation_expense: string,
     *             purchase_expense: string,
     *             months: list<array{month: int, sales: string, operation_expense: string, purchase_expense: string}>
     *         }>
     *     }>,
     *     meta: array{year: int, years: list<int>}
     * }
     */
    public function build(User $user, int $year): array
    {
        $companies = $this->sortCompanies($user->accessibleCompanies()->with('category')->get());
        $companyIds = array_map(intval(...), $companies->modelKeys());
        $posted = $this->posted($companyIds, $year);
        /** @var array<int, list<array{id: string, name: string, currency: string, sales: string, operation_expense: string, purchase_expense: string, months: list<array{month: int, sales: string, operation_expense: string, purchase_expense: string}>}>> $byCategory */
        $byCategory = [];
        $uncategorized = [];

        foreach ($companies as $company) {
            $row = $this->business($company, $posted[$company->id] ?? [], $year);

            if ($company->category_id === null) {
                $uncategorized[] = $row;

                continue;
            }

            $byCategory[(int) $company->category_id][] = $row;
        }

        $groups = [];

        foreach ($this->categories() as $category) {
            $groups[] = [
                'id' => (string) $category->id,
                'name' => $category->name,
                'businesses' => $byCategory[(int) $category->id] ?? [],
            ];
        }

        if ($uncategorized !== []) {
            $groups[] = [
                'id' => null,
                'name' => 'No category',
                'businesses' => $uncategorized,
            ];
        }

        return [
            'data' => $groups,
            'meta' => [
                'year' => $year,
                'years' => $this->years($companyIds),
            ],
        ];
    }

    /**
     * Posted sales, operation expenses, and purchase expenses for every day of one month.
     *
     * @return array{
     *     data: array{
     *         id: string,
     *         name: string,
     *         currency: string,
     *         year: int,
     *         month: int,
     *         sales: string,
     *         operation_expense: string,
     *         purchase_expense: string,
     *         days: list<array{day: int, sales: string, operation_expense: string, purchase_expense: string}>
     *     }
     * }
     */
    public function days(Company $company, int $year, int $month): array
    {
        $posted = $this->postedDays($company, $year, $month);
        $totals = $this->emptySeries();
        $days = [];
        $count = Carbon::create($year, $month, 1)->daysInMonth;

        for ($day = 1; $day <= $count; $day++) {
            $series = $posted[$day] ?? $this->emptySeries();
            $days[] = [
                'day' => $day,
                'sales' => $series['sales']->toDecimal(),
                'operation_expense' => $series['operation_expense']->toDecimal(),
                'purchase_expense' => $series['purchase_expense']->toDecimal(),
            ];

            foreach ($series as $name => $amount) {
                $totals[$name] = $totals[$name]->plus($amount);
            }
        }

        return [
            'data' => [
                'id' => (string) $company->id,
                'name' => $company->name,
                'currency' => $company->currency,
                'year' => $year,
                'month' => $month,
                'sales' => $totals['sales']->toDecimal(),
                'operation_expense' => $totals['operation_expense']->toDecimal(),
                'purchase_expense' => $totals['purchase_expense']->toDecimal(),
                'days' => $days,
            ],
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<int, array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>>
     */
    private function posted(array $companyIds, int $year): array
    {
        if ($companyIds === []) {
            return [];
        }

        $posted = [];
        $rows = ChartAccountEntry::query()
            ->withoutGlobalScope('company')
            ->join('accounts', 'accounts.id', '=', 'chart_account_entries.account_id')
            ->whereIn('chart_account_entries.company_id', $companyIds)
            ->where('chart_account_entries.status', ChartAccountEntryStatus::Posted->value)
            ->where('chart_account_entries.date', '>=', sprintf('%04d-01-01', $year))
            ->where('chart_account_entries.date', '<', Carbon::create($year + 1, 1, 1)->toDateString())
            ->whereIn('accounts.code', [
                IncomeExpenseChartReport::SALES,
                IncomeExpenseChartReport::OPERATION_EXPENSE,
                IncomeExpenseChartReport::PURCHASE_EXPENSE,
            ])
            ->toBase()
            ->select('chart_account_entries.company_id', 'accounts.code')
            ->selectRaw('substr(chart_account_entries.date, 1, 7) as month_key')
            ->selectRaw('sum(chart_account_entries.amount_cents) as amount_cents')
            ->groupBy('chart_account_entries.company_id', 'accounts.code')
            ->groupByRaw('substr(chart_account_entries.date, 1, 7)')
            ->get();

        foreach ($rows as $row) {
            $series = $this->seriesKey((string) $row->code);

            if ($series === null) {
                continue;
            }

            $companyId = (int) $row->company_id;
            $month = (string) $row->month_key;
            $posted[$companyId][$month] ??= $this->emptySeries();
            $posted[$companyId][$month][$series] = $posted[$companyId][$month][$series]->plus(
                Money::ofMinor((int) $row->amount_cents),
            );
        }

        return $posted;
    }

    /**
     * @return array<int, array{sales: Money, operation_expense: Money, purchase_expense: Money}>
     */
    private function postedDays(Company $company, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);
        $posted = [];
        $rows = ChartAccountEntry::query()
            ->withoutGlobalScope('company')
            ->join('accounts', 'accounts.id', '=', 'chart_account_entries.account_id')
            ->where('chart_account_entries.company_id', $company->id)
            ->where('chart_account_entries.status', ChartAccountEntryStatus::Posted->value)
            ->where('chart_account_entries.date', '>=', $start->toDateString())
            ->where('chart_account_entries.date', '<', $start->copy()->addMonth()->toDateString())
            ->whereIn('accounts.code', [
                IncomeExpenseChartReport::SALES,
                IncomeExpenseChartReport::OPERATION_EXPENSE,
                IncomeExpenseChartReport::PURCHASE_EXPENSE,
            ])
            ->toBase()
            ->select('accounts.code')
            ->selectRaw('substr(chart_account_entries.date, 9, 2) as day_key')
            ->selectRaw('sum(chart_account_entries.amount_cents) as amount_cents')
            ->groupBy('accounts.code')
            ->groupByRaw('substr(chart_account_entries.date, 9, 2)')
            ->get();

        foreach ($rows as $row) {
            $series = $this->seriesKey((string) $row->code);

            if ($series === null) {
                continue;
            }

            $day = (int) $row->day_key;
            $posted[$day] ??= $this->emptySeries();
            $posted[$day][$series] = $posted[$day][$series]->plus(
                Money::ofMinor((int) $row->amount_cents),
            );
        }

        return $posted;
    }

    /**
     * @param  array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>  $months
     * @return array{id: string, name: string, currency: string, sales: string, operation_expense: string, purchase_expense: string, months: list<array{month: int, sales: string, operation_expense: string, purchase_expense: string}>}
     */
    private function business(Company $company, array $months, int $year): array
    {
        $totals = $this->emptySeries();
        $points = [];

        for ($month = 1; $month <= 12; $month++) {
            $series = $months[sprintf('%04d-%02d', $year, $month)] ?? $this->emptySeries();
            $points[] = [
                'month' => $month,
                'sales' => $series['sales']->toDecimal(),
                'operation_expense' => $series['operation_expense']->toDecimal(),
                'purchase_expense' => $series['purchase_expense']->toDecimal(),
            ];

            foreach ($series as $name => $amount) {
                $totals[$name] = $totals[$name]->plus($amount);
            }
        }

        return [
            'id' => (string) $company->id,
            'name' => $company->name,
            'currency' => $company->currency,
            'sales' => $totals['sales']->toDecimal(),
            'operation_expense' => $totals['operation_expense']->toDecimal(),
            'purchase_expense' => $totals['purchase_expense']->toDecimal(),
            'months' => $points,
        ];
    }

    /**
     * @return array{sales: Money, operation_expense: Money, purchase_expense: Money}
     */
    private function emptySeries(): array
    {
        return [
            'sales' => Money::zero(),
            'operation_expense' => Money::zero(),
            'purchase_expense' => Money::zero(),
        ];
    }

    private function seriesKey(string $code): ?string
    {
        return match ($code) {
            IncomeExpenseChartReport::SALES => 'sales',
            IncomeExpenseChartReport::OPERATION_EXPENSE => 'operation_expense',
            IncomeExpenseChartReport::PURCHASE_EXPENSE => 'purchase_expense',
            default => null,
        };
    }

    /**
     * @return Collection<int, Category>
     */
    private function categories(): Collection
    {
        return Category::query()->get()->sort(function (Category $left, Category $right): int {
            $byName = $this->compareText($left->name, $right->name);

            return $byName !== 0 ? $byName : $left->id <=> $right->id;
        })->values();
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<int>
     */
    private function years(array $companyIds): array
    {
        $years = [];

        if ($companyIds !== []) {
            $years = ChartAccountEntry::query()
                ->withoutGlobalScope('company')
                ->whereIn('company_id', $companyIds)
                ->toBase()
                ->selectRaw('substr(date, 1, 4) as entry_year')
                ->distinct()
                ->get()
                ->map(fn (object $row): int => (int) $row->entry_year)
                ->all();
        }

        $current = (int) now()->year;

        if (! in_array($current, $years, true)) {
            $years[] = $current;
        }

        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return Collection<int, Company>
     */
    private function sortCompanies(Collection $companies): Collection
    {
        return $companies->sort(function (Company $left, Company $right): int {
            $leftCategory = $left->category?->name;
            $rightCategory = $right->category?->name;

            if ($leftCategory === null && $rightCategory !== null) {
                return 1;
            }

            if ($leftCategory !== null && $rightCategory === null) {
                return -1;
            }

            if ($leftCategory !== null && $rightCategory !== null) {
                $byCategory = $this->compareText($leftCategory, $rightCategory);

                if ($byCategory !== 0) {
                    return $byCategory;
                }
            }

            $byName = $this->compareText($left->name, $right->name);

            return $byName !== 0 ? $byName : $left->id <=> $right->id;
        })->values();
    }

    private function compareText(string $left, string $right): int
    {
        return strcmp(mb_strtolower($left), mb_strtolower($right));
    }
}
