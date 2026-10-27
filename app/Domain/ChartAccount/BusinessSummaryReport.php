<?php

namespace App\Domain\ChartAccount;

use App\Domain\Ledger\DefaultChart;
use App\Domain\Ledger\Money;
use App\Enums\AccountType;
use App\Enums\ChartAccountEntryStatus;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BusinessSummaryReport
{
    /**
     * Posted chart-account totals for every business the user can open.
     *
     * @return array{
     *     data: list<array{
     *         id: string,
     *         name: string,
     *         currency: string,
     *         classification_name: string|null,
     *         income: string,
     *         expense: string,
     *         result: string,
     *         drafts: int,
     *         accounts: list<array{
     *             id: string,
     *             code: string,
     *             name: string,
     *             type: string,
     *             amount: string,
     *             cash: string,
     *             credit: string,
     *             entries: int
     *         }>
     *     }>,
     *     meta: array{
     *         period: string,
     *         year: int,
     *         month: int|null,
     *         from: string,
     *         to: string,
     *         years: list<int>,
     *         totals: array{currency: string, income: string, expense: string, result: string}|null
     *     }
     * }
     */
    public function build(User $user, string $period, int $year, int $month): array
    {
        [$from, $to, $reportedMonth] = $this->bounds($period, $year, $month);
        $companies = $this->sortCompanies($user->accessibleCompanies()->with('classification')->get());
        $companyIds = array_map(intval(...), $companies->modelKeys());
        $defaultCodes = array_map(
            fn (array $account): string => $account['code'],
            DefaultChart::ACCOUNTS,
        );
        $accounts = $this->accounts($companyIds);
        $posted = $this->postedTotals($companyIds, $from, $to);
        $drafts = $this->draftCounts($companyIds, $from, $to);
        $rows = [];
        $income = Money::zero();
        $expense = Money::zero();
        $currencies = [];

        foreach ($companies as $company) {
            $row = $this->companyRow(
                $company,
                $accounts->get($company->id, collect()),
                $posted[$company->id] ?? [],
                $drafts[$company->id] ?? 0,
                $defaultCodes,
            );
            $rows[] = $row['payload'];
            $income = $income->plus($row['income']);
            $expense = $expense->plus($row['expense']);
            $currencies[$company->currency] = true;
        }

        return [
            'data' => $rows,
            'meta' => [
                'period' => $period === 'year' ? 'year' : 'month',
                'year' => $year,
                'month' => $reportedMonth,
                'from' => $from,
                'to' => $to,
                'years' => $this->years($companyIds),
                'totals' => $this->totals($rows, $income, $expense, $currencies),
            ],
        ];
    }

    /**
     * @param  list<int>  $companyIds
     * @return Collection<int|string, Collection<int, Account>>
     */
    private function accounts(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return collect();
        }

        return Account::query()
            ->withoutGlobalScope('company')
            ->whereIn('company_id', $companyIds)
            ->get()
            ->groupBy(fn (Account $account): int => (int) $account->company_id);
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<int, array<int, array{amount: int, cash: int, credit: int, entries: int}>>
     */
    private function postedTotals(array $companyIds, string $from, string $to): array
    {
        if ($companyIds === []) {
            return [];
        }

        $totals = [];
        $rows = ChartAccountEntry::query()
            ->withoutGlobalScope('company')
            ->whereIn('company_id', $companyIds)
            ->where('status', ChartAccountEntryStatus::Posted->value)
            ->whereBetween('date', [$from, $to])
            ->toBase()
            ->select('company_id', 'account_id')
            ->selectRaw('sum(amount_cents) as amount_cents')
            ->selectRaw('sum(cash_cents) as cash_cents')
            ->selectRaw('sum(credit_cents) as credit_cents')
            ->selectRaw('count(*) as entries_count')
            ->groupBy('company_id', 'account_id')
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->company_id][(int) $row->account_id] = [
                'amount' => (int) $row->amount_cents,
                'cash' => (int) $row->cash_cents,
                'credit' => (int) $row->credit_cents,
                'entries' => (int) $row->entries_count,
            ];
        }

        return $totals;
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<int, int>
     */
    private function draftCounts(array $companyIds, string $from, string $to): array
    {
        if ($companyIds === []) {
            return [];
        }

        $counts = [];
        $rows = ChartAccountEntry::query()
            ->withoutGlobalScope('company')
            ->whereIn('company_id', $companyIds)
            ->where('status', ChartAccountEntryStatus::Draft->value)
            ->whereBetween('date', [$from, $to])
            ->toBase()
            ->select('company_id')
            ->selectRaw('count(*) as drafts_count')
            ->groupBy('company_id')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->company_id] = (int) $row->drafts_count;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, array{amount: int, cash: int, credit: int, entries: int}>  $posted
     * @param  list<string>  $defaultCodes
     * @return array{
     *     payload: array{
     *         id: string,
     *         name: string,
     *         currency: string,
     *         classification_name: string|null,
     *         income: string,
     *         expense: string,
     *         result: string,
     *         drafts: int,
     *         accounts: list<array{
     *             id: string,
     *             code: string,
     *             name: string,
     *             type: string,
     *             amount: string,
     *             cash: string,
     *             credit: string,
     *             entries: int
     *         }>
     *     },
     *     income: Money,
     *     expense: Money
     * }
     */
    private function companyRow(Company $company, Collection $accounts, array $posted, int $drafts, array $defaultCodes): array
    {
        $income = Money::zero();
        $expense = Money::zero();
        $lines = [];

        foreach ($this->sortAccounts($accounts) as $account) {
            $totals = $posted[$account->id] ?? ['amount' => 0, 'cash' => 0, 'credit' => 0, 'entries' => 0];

            if (! $this->showsAccount($account, $totals['entries'], $defaultCodes)) {
                continue;
            }

            $amount = Money::ofMinor($totals['amount']);

            if ($account->type === AccountType::Income) {
                $income = $income->plus($amount);
            }

            if ($account->type === AccountType::Expense) {
                $expense = $expense->plus($amount);
            }

            $lines[] = [
                'id' => (string) $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'amount' => $amount->toDecimal(),
                'cash' => Money::ofMinor($totals['cash'])->toDecimal(),
                'credit' => Money::ofMinor($totals['credit'])->toDecimal(),
                'entries' => $totals['entries'],
            ];
        }

        return [
            'payload' => [
                'id' => (string) $company->id,
                'name' => $company->name,
                'currency' => $company->currency,
                'classification_name' => $company->classification?->name,
                'income' => $income->toDecimal(),
                'expense' => $expense->toDecimal(),
                'result' => Money::ofMinor($income->minor - $expense->minor)->toDecimal(),
                'drafts' => $drafts,
                'accounts' => $lines,
            ],
            'income' => $income,
            'expense' => $expense,
        ];
    }

    /**
     * @param  list<array{currency: string, income: string, expense: string, result: string}>  $rows
     * @param  array<string, true>  $currencies
     * @return array{currency: string, income: string, expense: string, result: string}|null
     */
    private function totals(array $rows, Money $income, Money $expense, array $currencies): ?array
    {
        if (count($rows) < 2 || count($currencies) !== 1) {
            return null;
        }

        return [
            'currency' => (string) array_key_first($currencies),
            'income' => $income->toDecimal(),
            'expense' => $expense->toDecimal(),
            'result' => Money::ofMinor($income->minor - $expense->minor)->toDecimal(),
        ];
    }

    /**
     * @param  list<string>  $defaultCodes
     */
    private function showsAccount(Account $account, int $entries, array $defaultCodes): bool
    {
        if ($entries > 0) {
            return true;
        }

        return $account->is_active && in_array($account->code, $defaultCodes, true);
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return Collection<int, Company>
     */
    private function sortCompanies(Collection $companies): Collection
    {
        return $companies->sort(function (Company $left, Company $right): int {
            $leftClassification = $left->classification?->name;
            $rightClassification = $right->classification?->name;

            if ($leftClassification === null && $rightClassification !== null) {
                return 1;
            }

            if ($leftClassification !== null && $rightClassification === null) {
                return -1;
            }

            if ($leftClassification !== null && $rightClassification !== null) {
                $byClassification = $this->compareText($leftClassification, $rightClassification);

                if ($byClassification !== 0) {
                    return $byClassification;
                }
            }

            $byName = $this->compareText($left->name, $right->name);

            return $byName !== 0 ? $byName : $left->id <=> $right->id;
        })->values();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    private function sortAccounts(Collection $accounts): Collection
    {
        return $accounts->sort(function (Account $left, Account $right): int {
            $byCode = strcmp($left->code, $right->code);

            return $byCode !== 0 ? $byCode : $left->id <=> $right->id;
        })->values();
    }

    private function compareText(string $left, string $right): int
    {
        return strcmp(mb_strtolower($left), mb_strtolower($right));
    }

    /**
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function bounds(string $period, int $year, int $month): array
    {
        if ($period === 'year') {
            return [
                Carbon::createFromDate($year, 1, 1)->toDateString(),
                Carbon::createFromDate($year, 12, 31)->toDateString(),
                null,
            ];
        }

        $start = Carbon::createFromDate($year, $month, 1);

        return [
            $start->toDateString(),
            $start->copy()->endOfMonth()->toDateString(),
            $month,
        ];
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
                ->pluck('date')
                ->map(function (mixed $date): int {
                    if ($date instanceof \DateTimeInterface) {
                        return (int) $date->format('Y');
                    }

                    return (int) Carbon::parse((string) $date)->year;
                })
                ->unique()
                ->all();
        }

        $current = (int) now()->year;

        if (! in_array($current, $years, true)) {
            $years[] = $current;
        }

        sort($years);

        return array_values($years);
    }
}
