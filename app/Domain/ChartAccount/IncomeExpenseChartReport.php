<?php

namespace App\Domain\ChartAccount;

use App\Domain\Ledger\Money;
use App\Enums\ChartAccountEntryStatus;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IncomeExpenseChartReport
{
    public const SALES = '4000';

    public const OPERATION_EXPENSE = '5000';

    public const PURCHASE_EXPENSE = '5100';

    /**
     * Posted sales, operation expenses, and purchase expenses for the last twelve months.
     *
     * @return array{
     *     data: list<array{
     *         id: string,
     *         name: string,
     *         currency: string,
     *         category_name: string|null,
     *         business_count: int,
     *         months: list<array{key: string, sales: string, operation_expense: string, purchase_expense: string}>
     *     }>,
     *     meta: array{
     *         group: string,
     *         from: string,
     *         to: string,
     *         months: list<array{key: string, year: int, month: int}>
     *     }
     * }
     */
    public function build(User $user, string $group): array
    {
        $months = $this->months();
        $from = $months[0]['start'];
        $to = $months[array_key_last($months)]['end'];
        $companies = $this->sortCompanies($user->accessibleCompanies()->with('category')->get());
        $posted = $this->posted($companies->modelKeys(), $from, $to);
        $charts = $group === 'category'
            ? $this->categoryCharts($companies, $posted, $months)
            : $this->businessCharts($companies, $posted, $months);

        return [
            'data' => $charts,
            'meta' => [
                'group' => $group === 'category' ? 'category' : 'business',
                'from' => $from,
                'to' => $to,
                'months' => array_map(fn (array $month): array => [
                    'key' => $month['key'],
                    'year' => $month['year'],
                    'month' => $month['month'],
                ], $months),
            ],
        ];
    }

    /**
     * @param  list<int|string>  $companyIds
     * @return array<int, array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>>
     */
    private function posted(array $companyIds, string $from, string $to): array
    {
        $companyIds = array_map(intval(...), $companyIds);

        if ($companyIds === []) {
            return [];
        }

        $posted = [];
        $rows = ChartAccountEntry::query()
            ->withoutGlobalScope('company')
            ->join('accounts', 'accounts.id', '=', 'chart_account_entries.account_id')
            ->whereIn('chart_account_entries.company_id', $companyIds)
            ->where('chart_account_entries.status', ChartAccountEntryStatus::Posted->value)
            ->where('chart_account_entries.date', '>=', $from)
            ->where('chart_account_entries.date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->whereIn('accounts.code', [self::SALES, self::OPERATION_EXPENSE, self::PURCHASE_EXPENSE])
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
     * @param  Collection<int, Company>  $companies
     * @param  array<int, array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>>  $posted
     * @param  list<array{key: string, year: int, month: int, start: string, end: string}>  $months
     * @return list<array{id: string, name: string, currency: string, category_name: string|null, business_count: int, months: list<array{key: string, sales: string, operation_expense: string, purchase_expense: string}>}>
     */
    private function businessCharts(Collection $companies, array $posted, array $months): array
    {
        $charts = [];

        foreach ($companies as $company) {
            $charts[] = $this->chart(
                (string) $company->id,
                $company->name,
                $company->currency,
                $company->category?->name,
                1,
                $posted[$company->id] ?? [],
                $months,
            );
        }

        return $charts;
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @param  array<int, array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>>  $posted
     * @param  list<array{key: string, year: int, month: int, start: string, end: string}>  $months
     * @return list<array{id: string, name: string, currency: string, category_name: string|null, business_count: int, months: list<array{key: string, sales: string, operation_expense: string, purchase_expense: string}>}>
     */
    private function categoryCharts(Collection $companies, array $posted, array $months): array
    {
        /** @var array<string, array{name: string, currency: string, category_name: string|null, business_count: int, buckets: array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>}> $groups */
        $groups = [];

        foreach ($companies as $company) {
            $key = ($company->category_id === null ? 'none' : (string) $company->category_id).':'.$company->currency;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $company->category?->name ?? 'No category',
                    'currency' => $company->currency,
                    'category_name' => $company->category?->name,
                    'business_count' => 0,
                    'buckets' => [],
                ];
            }

            $groups[$key]['business_count']++;
            $groups[$key]['buckets'] = $this->mergeBuckets($groups[$key]['buckets'], $posted[$company->id] ?? []);
        }

        $charts = [];

        foreach ($this->sortGroups($groups) as $key => $group) {
            $charts[] = $this->chart(
                'category:'.$key,
                $group['name'],
                $group['currency'],
                $group['category_name'],
                $group['business_count'],
                $group['buckets'],
                $months,
            );
        }

        return $charts;
    }

    /**
     * @param  array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>  $buckets
     * @param  list<array{key: string, year: int, month: int, start: string, end: string}>  $months
     * @return array{id: string, name: string, currency: string, category_name: string|null, business_count: int, months: list<array{key: string, sales: string, operation_expense: string, purchase_expense: string}>}
     */
    private function chart(string $id, string $name, string $currency, ?string $categoryName, int $businessCount, array $buckets, array $months): array
    {
        $points = [];

        foreach ($months as $month) {
            $series = $buckets[$month['key']] ?? $this->emptySeries();
            $points[] = [
                'key' => $month['key'],
                'sales' => $series['sales']->toDecimal(),
                'operation_expense' => $series['operation_expense']->toDecimal(),
                'purchase_expense' => $series['purchase_expense']->toDecimal(),
            ];
        }

        return [
            'id' => $id,
            'name' => $name,
            'currency' => $currency,
            'category_name' => $categoryName,
            'business_count' => $businessCount,
            'months' => $points,
        ];
    }

    /**
     * @param  array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>  $target
     * @param  array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>  $source
     * @return array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>
     */
    private function mergeBuckets(array $target, array $source): array
    {
        foreach ($source as $month => $series) {
            if (! isset($target[$month])) {
                $target[$month] = $series;

                continue;
            }

            foreach ($series as $key => $amount) {
                $target[$month][$key] = $target[$month][$key]->plus($amount);
            }
        }

        return $target;
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
            self::SALES => 'sales',
            self::OPERATION_EXPENSE => 'operation_expense',
            self::PURCHASE_EXPENSE => 'purchase_expense',
            default => null,
        };
    }

    /**
     * @return list<array{key: string, year: int, month: int, start: string, end: string}>
     */
    private function months(): array
    {
        $cursor = now()->startOfMonth()->subMonths(11);
        $months = [];

        for ($index = 0; $index < 12; $index++) {
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'year' => (int) $cursor->year,
                'month' => (int) $cursor->month,
                'start' => $cursor->toDateString(),
                'end' => $cursor->copy()->endOfMonth()->toDateString(),
            ];
            $cursor->addMonth();
        }

        return $months;
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

    /**
     * @param  array<string, array{name: string, currency: string, category_name: string|null, business_count: int, buckets: array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>}>  $groups
     * @return array<string, array{name: string, currency: string, category_name: string|null, business_count: int, buckets: array<string, array{sales: Money, operation_expense: Money, purchase_expense: Money}>}>
     */
    private function sortGroups(array $groups): array
    {
        uksort($groups, function (string $left, string $right) use ($groups): int {
            $leftGroup = $groups[$left];
            $rightGroup = $groups[$right];

            if ($leftGroup['category_name'] === null && $rightGroup['category_name'] !== null) {
                return 1;
            }

            if ($leftGroup['category_name'] !== null && $rightGroup['category_name'] === null) {
                return -1;
            }

            if ($leftGroup['category_name'] !== null && $rightGroup['category_name'] !== null) {
                $byCategory = $this->compareText($leftGroup['category_name'], $rightGroup['category_name']);

                if ($byCategory !== 0) {
                    return $byCategory;
                }
            }

            return $this->compareText($leftGroup['currency'], $rightGroup['currency']);
        });

        return $groups;
    }

    private function compareText(string $left, string $right): int
    {
        return strcmp(mb_strtolower($left), mb_strtolower($right));
    }
}
