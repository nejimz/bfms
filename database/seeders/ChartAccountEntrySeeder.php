<?php

namespace Database\Seeders;

use App\Domain\Ledger\Money;
use App\Enums\ChartAccountEntryStatus;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChartAccountEntrySeeder extends Seeder
{
    /**
     * Two years of chart account entries for Harbor Goods: 2025 and 2026 through October.
     */
    public function run(): void
    {
        $company = Company::query()->where('name', 'Harbor Goods')->first();

        if ($company === null) {
            return;
        }

        $owner = $company->users()->first();

        if (! $owner instanceof User) {
            return;
        }

        $alreadySeeded = ChartAccountEntry::query()
            ->where('company_id', $company->id)
            ->whereYear('date', 2025)
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $accounts = Account::query()
            ->where('company_id', $company->id)
            ->whereIn('code', array_keys($this->books()))
            ->get()
            ->keyBy('code');

        DB::transaction(function () use ($accounts, $company, $owner): void {
            $cursor = Carbon::create(2025, 1, 1)->startOfMonth();
            $end = Carbon::create(2026, 10, 1)->startOfMonth();

            while ($cursor->lte($end)) {
                foreach ($this->books() as $code => $book) {
                    $account = $accounts->get($code);

                    if (! $account instanceof Account) {
                        continue;
                    }

                    foreach ([0, 1] as $slot) {
                        $this->createEntry($company, $owner, $account, $book, $cursor->year, $cursor->month, $slot);
                    }
                }

                $cursor->addMonth();
            }
        });
    }

    /**
     * @param  array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}  $book
     */
    private function createEntry(Company $company, User $owner, Account $account, array $book, int $year, int $month, int $slot): void
    {
        $day = $year === 2026 && $month === 10
            ? ($slot === 0 ? 8 : 21)
            : ($slot === 0 ? 8 : 22);
        $date = Carbon::create($year, $month, $day)->toDateString();
        $draft = $year === 2026 && $month === 10 && $slot === 1;
        $party = $book['parties'][($month + $slot) % count($book['parties'])];
        $quantity = $account->code === '5000' ? 1 : 1 + (($month + $slot) % 4);
        $lines = [];

        foreach (range(0, $slot === 0 ? 1 : 0) as $offset) {
            $item = $book['items'][($month + $slot + $offset) % count($book['items'])];
            $lines[] = [
                'name' => $item['name'],
                'quantity' => (string) $quantity,
                'amount' => Money::ofMinor($quantity * ($item['unit_cents'] + ($month * $item['step'])))->toDecimal(),
            ];
        }

        $entry = ChartAccountEntry::query()->create([
            'company_id' => $company->id,
            'account_id' => $account->id,
            'date' => $date,
            'particulars' => $party,
            'amount_cents' => 0,
            'currency' => $company->currency,
            'status' => $draft ? ChartAccountEntryStatus::Draft : ChartAccountEntryStatus::Posted,
            'posted_at' => $draft ? null : Carbon::parse($date)->setTime(9, 0),
            'created_by' => $owner->id,
            'posted_by' => $draft ? null : $owner->id,
        ]);

        $entry->replaceLines($lines);
    }

    /**
     * @return array<string, array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}>
     */
    private function books(): array
    {
        return [
            '4000' => [
                'parties' => ['Harbor Market', 'Northwind Trading', 'Green Basket', 'Pier Cafe', 'South Dock Store', 'Lina Cruz'],
                'items' => [
                    ['name' => 'Rice', 'unit_cents' => 5000, 'step' => 25],
                    ['name' => 'Sugar', 'unit_cents' => 2500, 'step' => 15],
                    ['name' => 'Cooking oil', 'unit_cents' => 8500, 'step' => 40],
                    ['name' => 'Coffee', 'unit_cents' => 12000, 'step' => 50],
                    ['name' => 'Soap', 'unit_cents' => 3500, 'step' => 10],
                    ['name' => 'Eggs', 'unit_cents' => 1800, 'step' => 20],
                ],
            ],
            '5000' => [
                'parties' => ['Harbor Rentals', 'City Power', 'Metro Water', 'Fuel Stop', 'Bay Telecom', 'Rosa Santos'],
                'items' => [
                    ['name' => 'Rent', 'unit_cents' => 1500000, 'step' => 0],
                    ['name' => 'Electricity', 'unit_cents' => 420000, 'step' => 3500],
                    ['name' => 'Water', 'unit_cents' => 85000, 'step' => 500],
                    ['name' => 'Fuel', 'unit_cents' => 250000, 'step' => 2000],
                    ['name' => 'Internet', 'unit_cents' => 199900, 'step' => 0],
                    ['name' => 'Wages', 'unit_cents' => 800000, 'step' => 0],
                ],
            ],
            '5100' => [
                'parties' => ['Northwind Supplies', 'Eastmill Grains', 'Cebu Mill', 'Harbor Market', 'West Wharf Trading', 'Palengke Wholesale'],
                'items' => [
                    ['name' => 'Rice', 'unit_cents' => 145000, 'step' => 200],
                    ['name' => 'Sugar', 'unit_cents' => 32000, 'step' => 100],
                    ['name' => 'Flour', 'unit_cents' => 28000, 'step' => 80],
                    ['name' => 'Cooking oil', 'unit_cents' => 9600, 'step' => 40],
                    ['name' => 'Vinegar', 'unit_cents' => 4500, 'step' => 15],
                    ['name' => 'Salt', 'unit_cents' => 1800, 'step' => 10],
                ],
            ],
        ];
    }
}
