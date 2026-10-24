<?php

namespace App\Domain\ChartAccount;

use App\Domain\Ledger\Money;
use App\Models\Account;
use App\Models\AccountColumnReport;
use App\Models\ChartAccountEntry;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;

class ChartAccountColumnReport
{
    /**
     * @return array{
     *     data: list<array{id: string, date: string, particulars: string, reference: string|null, status: string, account_id: string, amount: string, cash: string, credit: string}>,
     *     meta: array{
     *         year: int,
     *         sort: string,
     *         currency: string,
     *         years: list<int>,
     *         columns: list<array{id: string, code: string, name: string}>
     *     }
     * }
     */
    public function build(AccountColumnReport $report, int $year, string $sort): array
    {
        $company = app(CurrentCompany::class)->company();
        $direction = $sort === 'desc' ? 'desc' : 'asc';
        $start = Carbon::createFromDate($year, 1, 1)->toDateString();
        $end = Carbon::createFromDate($year, 12, 31)->toDateString();
        $report->loadMissing('accounts');
        $accounts = $report->accounts;

        $entries = ChartAccountEntry::query()
            ->with('account')
            ->whereIn('account_id', $accounts->modelKeys())
            ->whereBetween('date', [$start, $end])
            ->orderBy('date', $direction)
            ->orderBy('id', $direction)
            ->get();

        $columns = $accounts
            ->map(fn (Account $account): array => [
                'id' => (string) $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->all();

        $data = $entries->map(fn (ChartAccountEntry $entry): array => [
            'id' => (string) $entry->id,
            'date' => $entry->date->toDateString(),
            'particulars' => $entry->particulars,
            'reference' => $entry->reference,
            'status' => $entry->status->value,
            'account_id' => (string) $entry->account_id,
            'amount' => Money::ofMinor((int) $entry->amount_cents)->toDecimal(),
            'cash' => Money::ofMinor((int) $entry->cash_cents)->toDecimal(),
            'credit' => Money::ofMinor((int) $entry->credit_cents)->toDecimal(),
        ])->all();

        return [
            'data' => $data,
            'meta' => [
                'year' => $year,
                'sort' => $direction,
                'currency' => $company->currency,
                'years' => $this->years(),
                'columns' => $columns,
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        $years = ChartAccountEntry::query()
            ->pluck('date')
            ->map(function (mixed $date): int {
                if ($date instanceof \DateTimeInterface) {
                    return (int) $date->format('Y');
                }

                return (int) Carbon::parse((string) $date)->year;
            })
            ->unique()
            ->all();

        $current = (int) now()->year;

        if (! in_array($current, $years, true)) {
            $years[] = $current;
        }

        sort($years);

        return array_values($years);
    }
}
