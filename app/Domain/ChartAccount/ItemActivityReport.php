<?php

namespace App\Domain\ChartAccount;

use App\Domain\Ledger\Money;
use App\Enums\ChartAccountEntryStatus;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\ChartAccountEntryLine;
use App\Models\Company;
use Illuminate\Support\Carbon;

class ItemActivityReport
{
    /**
     * Books that record item lines, keyed the same way as the registers.
     *
     * @var array<string, array{code: string, name: string, item_label: string}>
     */
    public const BOOKS = [
        'sales' => [
            'code' => '4000',
            'name' => 'Sales',
            'item_label' => 'Item sold',
        ],
        'operation-expense' => [
            'code' => '5000',
            'name' => 'Operation Expenses',
            'item_label' => 'Item',
        ],
        'purchase-expense' => [
            'code' => '5100',
            'name' => 'Purchase Expenses',
            'item_label' => 'Item purchased',
        ],
    ];

    /**
     * Posted item quantities and amounts for one book of the current business.
     *
     * @return array{
     *     data: list<array{
     *         name: string,
     *         periods: list<array{key: string, quantity: string, amount: string}>,
     *         total: array{quantity: string, amount: string, entries: int, average: string|null}
     *     }>,
     *     meta: array{
     *         book: string,
     *         grain: string,
     *         account_code: string,
     *         account_name: string,
     *         currency: string,
     *         item_label: string,
     *         year: int|null,
     *         month: int|null,
     *         from: string,
     *         to: string,
     *         periods: list<array{key: string, label: string}>,
     *         years: list<int>,
     *         totals: array{
     *             periods: list<array{key: string, quantity: string, amount: string}>,
     *             total: array{quantity: string, amount: string, entries: int, average: string|null}
     *         }
     *     }
     * }
     */
    public function build(Company $company, string $book, string $grain, int $year, int $month): array
    {
        $definition = self::BOOKS[$book];
        $years = $this->years($company, $definition['code']);
        [$from, $to, $reportedYear, $reportedMonth] = $this->bounds($grain, $year, $month, $years);
        $rows = $this->lines($company, $definition['code'], $from, $to);
        $periods = $this->periods($grain, $reportedYear, $reportedMonth, $years, $rows);
        $aggregated = $this->aggregate($rows, array_column($periods, 'key'), $grain);
        $account = Account::query()
            ->where('company_id', $company->id)
            ->where('code', $definition['code'])
            ->first();

        return [
            'data' => $aggregated['items'],
            'meta' => [
                'book' => $book,
                'grain' => $grain,
                'account_code' => $definition['code'],
                'account_name' => $account instanceof Account ? $account->name : $definition['name'],
                'currency' => $company->currency,
                'item_label' => $definition['item_label'],
                'year' => $reportedYear,
                'month' => $reportedMonth,
                'from' => $from,
                'to' => $to,
                'periods' => $periods,
                'years' => $years,
                'totals' => $aggregated['totals'],
            ],
        ];
    }

    /**
     * @param  list<int>  $years
     * @return array{0: string, 1: string, 2: int|null, 3: int|null}
     */
    private function bounds(string $grain, int $year, int $month, array $years): array
    {
        if ($grain === 'year') {
            $first = $years[0];
            $last = $years[array_key_last($years)];

            return [
                Carbon::createFromDate($first, 1, 1)->toDateString(),
                Carbon::createFromDate($last, 12, 31)->toDateString(),
                null,
                null,
            ];
        }

        if ($grain === 'month') {
            return [
                Carbon::createFromDate($year, 1, 1)->toDateString(),
                Carbon::createFromDate($year, 12, 31)->toDateString(),
                $year,
                null,
            ];
        }

        $start = Carbon::createFromDate($year, $month, 1)->startOfDay();

        return [
            $start->toDateString(),
            $start->copy()->endOfMonth()->toDateString(),
            $year,
            $month,
        ];
    }

    /**
     * @param  list<int>  $years
     * @param  list<object>  $rows
     * @return list<array{key: string, label: string}>
     */
    private function periods(string $grain, ?int $year, ?int $month, array $years, array $rows): array
    {
        return match ($grain) {
            'day' => $this->dayPeriods($rows),
            'week' => $this->weekPeriods((int) $year, (int) $month),
            'month' => $this->monthPeriods((int) $year),
            'year' => $this->yearPeriods($years),
            default => throw new \InvalidArgumentException('Unknown item report grain.'),
        };
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{key: string, label: string}>
     */
    private function dayPeriods(array $rows): array
    {
        $dates = [];

        foreach ($rows as $row) {
            if (trim((string) $row->name) === '') {
                continue;
            }

            $dates[$this->dateString($row->date)] = true;
        }

        $keys = array_keys($dates);
        sort($keys);

        return array_map(fn (string $date): array => [
            'key' => $date,
            'label' => Carbon::parse($date)->format('j M'),
        ], $keys);
    }

    /**
     * Monday weeks that overlap the month. A week that crosses the month boundary
     * keeps only the days inside the month, and the label matches those days.
     *
     * @return list<array{key: string, label: string}>
     */
    private function weekPeriods(int $year, int $month): array
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
        $periods = [];

        while ($cursor->lte($end)) {
            $weekStart = $cursor->copy();
            $weekEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
            $sliceStart = $weekStart->greaterThan($start) ? $weekStart : $start;
            $sliceEnd = $weekEnd->lessThan($end) ? $weekEnd : $end;
            $periods[] = [
                'key' => $weekStart->toDateString(),
                'label' => $this->sliceLabel($sliceStart, $sliceEnd),
            ];
            $cursor->addWeek();
        }

        return $periods;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function monthPeriods(int $year): array
    {
        $periods = [];

        for ($month = 1; $month <= 12; $month++) {
            $date = Carbon::createFromDate($year, $month, 1);
            $periods[] = [
                'key' => $date->format('Y-m'),
                'label' => $date->format('M'),
            ];
        }

        return $periods;
    }

    /**
     * @param  list<int>  $years
     * @return list<array{key: string, label: string}>
     */
    private function yearPeriods(array $years): array
    {
        return array_map(fn (int $year): array => [
            'key' => (string) $year,
            'label' => (string) $year,
        ], $years);
    }

    private function sliceLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('j M');
        }

        return $from->format('j').'–'.$to->format('j M');
    }

    /**
     * @return list<object>
     */
    private function lines(Company $company, string $code, string $from, string $to): array
    {
        return ChartAccountEntryLine::query()
            ->join('chart_account_entries', 'chart_account_entries.id', '=', 'chart_account_entry_lines.chart_account_entry_id')
            ->join('accounts', 'accounts.id', '=', 'chart_account_entries.account_id')
            ->where('chart_account_entry_lines.company_id', $company->id)
            ->where('chart_account_entries.status', ChartAccountEntryStatus::Posted->value)
            ->where('accounts.code', $code)
            ->whereBetween('chart_account_entries.date', [$from, $to])
            ->orderBy('chart_account_entries.date')
            ->orderBy('chart_account_entries.id')
            ->orderBy('chart_account_entry_lines.id')
            ->toBase()
            ->get([
                'chart_account_entry_lines.id as id',
                'chart_account_entry_lines.name as name',
                'chart_account_entry_lines.quantity as quantity',
                'chart_account_entry_lines.amount_cents as amount_cents',
                'chart_account_entries.id as entry_id',
                'chart_account_entries.date as date',
            ])
            ->all();
    }

    /**
     * @param  list<object>  $rows
     * @param  list<string>  $periodKeys
     * @return array{
     *     items: list<array{
     *         name: string,
     *         periods: list<array{key: string, quantity: string, amount: string}>,
     *         total: array{quantity: string, amount: string, entries: int, average: string|null}
     *     }>,
     *     totals: array{
     *         periods: list<array{key: string, quantity: string, amount: string}>,
     *         total: array{quantity: string, amount: string, entries: int, average: string|null}
     *     }
     * }
     */
    private function aggregate(array $rows, array $periodKeys, string $grain): array
    {
        /** @var array<string, array{name: string, periods: array<string, array{quantity: string, amount: Money}>, quantity: string, amount: Money, entries: array<int, true>}> $items */
        $items = [];
        /** @var array<string, array{quantity: string, amount: Money}> $periodTotals */
        $periodTotals = [];

        foreach ($periodKeys as $periodKey) {
            $periodTotals[$periodKey] = ['quantity' => '0', 'amount' => Money::zero()];
        }

        $grandQuantity = '0';
        $grandAmount = Money::zero();
        /** @var array<int, true> $grandEntries */
        $grandEntries = [];

        foreach ($rows as $row) {
            $name = trim((string) $row->name);

            if ($name === '') {
                continue;
            }

            $date = $this->dateString($row->date);
            $periodKey = $this->periodKey($grain, $date);

            if (! array_key_exists($periodKey, $periodTotals)) {
                continue;
            }

            $itemKey = mb_strtolower($name);
            $quantity = $this->quantityValue($row->quantity);
            $amount = Money::ofMinor((int) $row->amount_cents);
            $entryId = (int) $row->entry_id;

            if (! isset($items[$itemKey])) {
                $periods = [];

                foreach ($periodKeys as $key) {
                    $periods[$key] = ['quantity' => '0', 'amount' => Money::zero()];
                }

                $items[$itemKey] = [
                    'name' => $name,
                    'periods' => $periods,
                    'quantity' => '0',
                    'amount' => Money::zero(),
                    'entries' => [],
                ];
            }

            $items[$itemKey]['periods'][$periodKey]['quantity'] = bcadd($items[$itemKey]['periods'][$periodKey]['quantity'], $quantity, 3);
            $items[$itemKey]['periods'][$periodKey]['amount'] = $items[$itemKey]['periods'][$periodKey]['amount']->plus($amount);
            $items[$itemKey]['quantity'] = bcadd($items[$itemKey]['quantity'], $quantity, 3);
            $items[$itemKey]['amount'] = $items[$itemKey]['amount']->plus($amount);
            $items[$itemKey]['entries'][$entryId] = true;

            $periodTotals[$periodKey]['quantity'] = bcadd($periodTotals[$periodKey]['quantity'], $quantity, 3);
            $periodTotals[$periodKey]['amount'] = $periodTotals[$periodKey]['amount']->plus($amount);
            $grandQuantity = bcadd($grandQuantity, $quantity, 3);
            $grandAmount = $grandAmount->plus($amount);
            $grandEntries[$entryId] = true;
        }

        $sorted = array_values($items);
        usort($sorted, function (array $left, array $right): int {
            $byName = strcmp(mb_strtolower($left['name']), mb_strtolower($right['name']));

            return $byName !== 0 ? $byName : strcmp($left['name'], $right['name']);
        });

        return [
            'items' => array_map(fn (array $item): array => [
                'name' => $item['name'],
                'periods' => $this->cells($periodKeys, $item['periods']),
                'total' => $this->total($item['quantity'], $item['amount'], count($item['entries'])),
            ], $sorted),
            'totals' => [
                'periods' => $this->cells($periodKeys, $periodTotals),
                'total' => $this->total($grandQuantity, $grandAmount, count($grandEntries)),
            ],
        ];
    }

    /**
     * @param  list<string>  $periodKeys
     * @param  array<string, array{quantity: string, amount: Money}>  $cells
     * @return list<array{key: string, quantity: string, amount: string}>
     */
    private function cells(array $periodKeys, array $cells): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'quantity' => $this->quantityString($cells[$key]['quantity']),
            'amount' => $cells[$key]['amount']->toDecimal(),
        ], $periodKeys);
    }

    /**
     * @return array{quantity: string, amount: string, entries: int, average: string|null}
     */
    private function total(string $quantity, Money $amount, int $entries): array
    {
        return [
            'quantity' => $this->quantityString($quantity),
            'amount' => $amount->toDecimal(),
            'entries' => $entries,
            'average' => $this->averageUnit($amount->minor, $quantity),
        ];
    }

    private function periodKey(string $grain, string $date): string
    {
        return match ($grain) {
            'day' => $date,
            'week' => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
            'month' => substr($date, 0, 7),
            'year' => substr($date, 0, 4),
            default => throw new \InvalidArgumentException('Unknown item report grain.'),
        };
    }

    private function dateString(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }

    private function quantityValue(mixed $quantity): string
    {
        if (is_string($quantity) && preg_match('/^-?\d+(?:\.\d+)?$/', $quantity) === 1) {
            return bcadd($quantity, '0', 3);
        }

        return bcadd(sprintf('%.3F', (float) $quantity), '0', 3);
    }

    private function quantityString(string $quantity): string
    {
        $normalized = bcadd($quantity, '0', 3);
        $trimmed = rtrim(rtrim($normalized, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    private function averageUnit(int $cents, string $quantity): ?string
    {
        if (bccomp($quantity, '0', 3) !== 1) {
            return null;
        }

        $scaled = bcdiv((string) $cents, $quantity, 1);
        $negative = str_starts_with($scaled, '-');
        $absolute = ltrim($scaled, '-');
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '0');

        if ((int) $fraction >= 5) {
            $whole = bcadd($whole, '1', 0);
        }

        $minor = (int) $whole;

        return Money::ofMinor($negative ? -$minor : $minor)->toDecimal();
    }

    /**
     * @return list<int>
     */
    private function years(Company $company, string $code): array
    {
        $years = ChartAccountEntry::query()
            ->join('accounts', 'accounts.id', '=', 'chart_account_entries.account_id')
            ->where('chart_account_entries.company_id', $company->id)
            ->where('chart_account_entries.status', ChartAccountEntryStatus::Posted->value)
            ->where('accounts.code', $code)
            ->pluck('chart_account_entries.date')
            ->map(function (mixed $date): int {
                return (int) substr($this->dateString($date), 0, 4);
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
