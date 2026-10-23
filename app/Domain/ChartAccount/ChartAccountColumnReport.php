<?php

namespace App\Domain\ChartAccount;

use App\Domain\Ledger\Money;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;

class ChartAccountColumnReport
{
    /**
     * @return array{
     *     data: list<array{id: string, date: string, particulars: string, status: string, account_id: string, amount: string}>,
     *     meta: array{
     *         year: int,
     *         month: int,
     *         sort: string,
     *         currency: string,
     *         years: list<int>,
     *         columns: list<array{id: string, code: string, name: string}>
     *     }
     * }
     */
    public function build(int $year, int $month, string $sort): array
    {
        $company = app(CurrentCompany::class)->company();
        $direction = $sort === 'desc' ? 'desc' : 'asc';
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $entries = ChartAccountEntry::query()
            ->with('account')
            ->whereBetween('date', [$start, $end])
            ->orderBy('date', $direction)
            ->orderBy('id', $direction)
            ->get();

        $columns = $entries
            ->map(fn (ChartAccountEntry $entry) => $entry->account)
            ->filter()
            ->unique(fn (Account $account): int => $account->id)
            ->sortBy('code')
            ->values()
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
            'status' => $entry->status->value,
            'account_id' => (string) $entry->account_id,
            'amount' => Money::ofMinor((int) $entry->amount_cents)->toDecimal(),
        ])->all();

        return [
            'data' => $data,
            'meta' => [
                'year' => $year,
                'month' => $month,
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
