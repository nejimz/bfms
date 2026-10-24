<?php

namespace Database\Seeders;

use App\Domain\Ledger\DefaultChart;
use App\Domain\Ledger\Money;
use App\Enums\ChartAccountEntryStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChartAccountEntrySeeder extends Seeder
{
    /**
     * Ten business kinds, each with a chart of accounts and two years of entries.
     * Every other business that is still missing 2025 entries receives the same span.
     */
    public function run(): void
    {
        $owner = User::query()->where('email', 'owner@bfms.test')->first();

        if (! $owner instanceof User) {
            return;
        }

        foreach ($this->kinds() as $kind) {
            $category = Category::query()->firstOrCreate(['name' => $kind['category']]);
            $company = Company::query()->firstOrCreate(
                ['name' => $kind['name']],
                [
                    'description' => $kind['description'],
                    'currency' => 'PHP',
                    'category_id' => $category->id,
                ],
            );
            $this->ensureChart($company);
        }

        Company::query()->orderBy('id')->each(function (Company $company) use ($owner): void {
            $this->ensureChart($company);

            if ($this->alreadySeeded($company)) {
                return;
            }

            $this->seedCompany($company, $owner, $this->booksFor($company->name));
        });
    }

    /**
     * @param  array<string, array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}>  $books
     */
    private function seedCompany(Company $company, User $owner, array $books): void
    {
        $accounts = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->whereIn('code', array_keys($books))
            ->get()
            ->keyBy('code');

        DB::transaction(function () use ($accounts, $books, $company, $owner): void {
            $cursor = Carbon::create(2025, 1, 1)->startOfMonth();
            $end = Carbon::create(2026, 10, 1)->startOfMonth();

            while ($cursor->lte($end)) {
                foreach ($books as $code => $book) {
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

    private function ensureChart(Company $company): void
    {
        foreach (DefaultChart::ACCOUNTS as $account) {
            Account::query()->withoutGlobalScope('company')->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $account['code'],
                ],
                [
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function alreadySeeded(Company $company): bool
    {
        return ChartAccountEntry::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->whereYear('date', 2025)
            ->exists();
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
        $entry->forceFill([
            'cash_cents' => $entry->amount_cents,
            'credit_cents' => 0,
        ])->save();
    }

    /**
     * @return array<string, array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}>
     */
    private function booksFor(string $name): array
    {
        foreach ($this->kinds() as $kind) {
            if ($kind['name'] === $name) {
                return $kind['books'];
            }
        }

        if ($name === 'Harbor Goods') {
            return $this->harborBooks();
        }

        return $this->generalBooks();
    }

    /**
     * @return list<array{category: string, name: string, description: string, books: array<string, array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}>}>
     */
    private function kinds(): array
    {
        return [
            [
                'category' => 'Grocery',
                'name' => 'Palengke Fresh',
                'description' => 'Wet market stall for rice, produce, and household goods.',
                'books' => [
                    '4000' => $this->book(
                        ['Harbor Market', 'Northwind Trading', 'Green Basket', 'South Dock Store', 'Lina Cruz', 'Pier Cafe'],
                        [['Rice', 420000, 800], ['Vegetables', 180000, 400], ['Cooking oil', 210000, 300], ['Canned goods', 150000, 200], ['Soap', 80000, 100], ['Eggs', 90000, 150]],
                    ),
                    '5000' => $this->book(
                        ['Stall Rentals', 'City Power', 'Metro Water', 'Rosa Santos', 'Bay Telecom', 'Fuel Stop'],
                        [['Rent', 1500000, 0], ['Electricity', 280000, 2000], ['Water', 65000, 400], ['Wages', 900000, 0], ['Internet', 149900, 0], ['Hauling', 120000, 500]],
                    ),
                    '5100' => $this->book(
                        ['Northwind Supplies', 'Eastmill Grains', 'Cebu Mill', 'Palengke Wholesale', 'West Wharf Trading', 'Harbor Market'],
                        [['Rice sack', 250000, 400], ['Sugar', 70000, 150], ['Flour', 60000, 100], ['Cooking oil', 110000, 200], ['Vinegar', 18000, 40], ['Salt', 12000, 20]],
                    ),
                ],
            ],
            [
                'category' => 'Bakery',
                'name' => 'Morning Crust',
                'description' => 'Neighborhood bakery for bread, cakes, and pastries.',
                'books' => [
                    '4000' => $this->book(
                        ['Morning Regulars', 'School Canteen', 'Cafe Norte', 'Barangay Fiesta', 'Office Pantry', 'Walk-in'],
                        [['Pandesal', 180000, 200], ['Loaf bread', 220000, 250], ['Cakes', 350000, 400], ['Cookies', 90000, 80], ['Ensaymada', 140000, 120], ['Coffee', 70000, 50]],
                    ),
                    '5000' => $this->book(
                        ['Corner Lease', 'City Power', 'Metro Water', 'Baker Wages', 'Gas Depot', 'Bay Telecom'],
                        [['Rent', 800000, 0], ['Electricity', 420000, 2500], ['Water', 45000, 200], ['Wages', 700000, 0], ['Gas', 180000, 800], ['Internet', 99900, 0]],
                    ),
                    '5100' => $this->book(
                        ['Eastmill Grains', 'Dairy Cooperative', 'Sugar Mill', 'Packaging Hub', 'Yeast Supply', 'Butter House'],
                        [['Flour', 160000, 300], ['Sugar', 55000, 100], ['Butter', 140000, 200], ['Eggs', 48000, 80], ['Yeast', 22000, 20], ['Boxes', 30000, 40]],
                    ),
                ],
            ],
            [
                'category' => 'Pharmacy',
                'name' => 'Botica Rosa',
                'description' => 'Corner pharmacy for medicines and personal care.',
                'books' => [
                    '4000' => $this->book(
                        ['Walk-in Patients', 'Barangay Clinic', 'Senior Discount', 'Company Account', 'Delivery', 'Night Counter'],
                        [['Medicines', 650000, 900], ['Vitamins', 280000, 300], ['First aid', 120000, 100], ['Personal care', 90000, 80], ['Baby care', 150000, 120], ['Prescription fee', 50000, 0]],
                    ),
                    '5000' => $this->book(
                        ['Shop Lease', 'City Power', 'Cold Chain', 'Pharmacist', 'Bay Telecom', 'Guard Service'],
                        [['Rent', 2000000, 0], ['Electricity', 350000, 2000], ['Refrigeration', 220000, 500], ['Wages', 1400000, 0], ['Internet', 159900, 0], ['Security', 400000, 0]],
                    ),
                    '5100' => $this->book(
                        ['MedSource', 'Generic Wholesale', 'City Distributor', 'Care Supplies', 'Lab Kits', 'Wellness Depot'],
                        [['Medicines', 480000, 700], ['Vitamins', 190000, 250], ['Supplies', 80000, 100], ['Bottles', 25000, 20], ['Gloves', 40000, 50], ['Test kits', 110000, 150]],
                    ),
                ],
            ],
            [
                'category' => 'Hardware',
                'name' => 'Bolt and Timber',
                'description' => 'Hardware store for tools, lumber, and building supplies.',
                'books' => [
                    '4000' => $this->book(
                        ['Site Foreman', 'Home Repair', 'Contractor Jose', 'Barangay Hall', 'School Works', 'Walk-in'],
                        [['Lumber', 800000, 1200], ['Cement', 420000, 600], ['Tools', 350000, 200], ['Paint', 210000, 250], ['Nails', 60000, 40], ['Pipes', 180000, 150]],
                    ),
                    '5000' => $this->book(
                        ['Warehouse Lease', 'City Power', 'Yard Crew', 'Delivery Truck', 'Bay Telecom', 'Security'],
                        [['Rent', 1200000, 0], ['Electricity', 260000, 1500], ['Wages', 850000, 0], ['Fuel', 220000, 1000], ['Internet', 129900, 0], ['Security', 300000, 0]],
                    ),
                    '5100' => $this->book(
                        ['Timber Yard', 'Cement Plant', 'Steel Depot', 'Paint Mill', 'Tool Import', 'Pipe Works'],
                        [['Lumber', 520000, 800], ['Cement', 280000, 400], ['Steel', 300000, 350], ['Paint', 140000, 180], ['Tools', 200000, 100], ['Fittings', 70000, 60]],
                    ),
                ],
            ],
            [
                'category' => 'Eatery',
                'name' => 'Mesa Nina',
                'description' => 'Cooked-food counter serving lunch and merienda.',
                'books' => [
                    '4000' => $this->book(
                        ['Lunch Crowd', 'Office Delivery', 'Fiesta Order', 'Walk-in', 'School Order', 'Catering'],
                        [['Rice meals', 380000, 400], ['Noodles', 160000, 150], ['Grill', 240000, 250], ['Drinks', 70000, 40], ['Dessert', 50000, 30], ['Catering tray', 300000, 200]],
                    ),
                    '5000' => $this->book(
                        ['Carinderia Lease', 'City Power', 'LPG Dealer', 'Kitchen Crew', 'Metro Water', 'Bay Telecom'],
                        [['Rent', 900000, 0], ['Electricity', 310000, 1800], ['Gas', 240000, 900], ['Wages', 1200000, 0], ['Water', 55000, 300], ['Internet', 99900, 0]],
                    ),
                    '5100' => $this->book(
                        ['Wet Market', 'Meat Vendor', 'Vegetable Stall', 'Rice Dealer', 'Sauce House', 'Drink Wholesale'],
                        [['Meat', 220000, 300], ['Vegetables', 80000, 120], ['Rice', 90000, 100], ['Oil', 70000, 80], ['Sauces', 35000, 30], ['Drinks', 60000, 40]],
                    ),
                ],
            ],
            [
                'category' => 'Laundry',
                'name' => 'Washday',
                'description' => 'Wash, dry, and fold service for households.',
                'books' => [
                    '4000' => $this->book(
                        ['Household Accounts', 'Dormitory', 'Uniform Contract', 'Walk-in', 'Hotel Linen', 'Pickup Route'],
                        [['Wash and fold', 260000, 200], ['Dry clean', 180000, 150], ['Blankets', 90000, 80], ['Uniforms', 140000, 100], ['Delivery', 40000, 20], ['Express', 70000, 30]],
                    ),
                    '5000' => $this->book(
                        ['Shop Lease', 'City Power', 'Metro Water', 'Attendants', 'Machine Service', 'Bay Telecom'],
                        [['Rent', 700000, 0], ['Electricity', 480000, 3000], ['Water', 160000, 800], ['Wages', 650000, 0], ['Repairs', 120000, 0], ['Internet', 89900, 0]],
                    ),
                    '5100' => $this->book(
                        ['Soap Distributor', 'Packaging Hub', 'Hanger Supply', 'Chemical House', 'Bag Printer', 'Spare Parts'],
                        [['Detergent', 90000, 100], ['Softener', 45000, 40], ['Plastic bags', 20000, 10], ['Hangers', 15000, 10], ['Bleach', 25000, 20], ['Parts', 60000, 50]],
                    ),
                ],
            ],
            [
                'category' => 'Transport',
                'name' => 'Bayan Rides',
                'description' => 'Small route transport for passengers and parcels.',
                'books' => [
                    '4000' => $this->book(
                        ['Route Passengers', 'Parcel Desk', 'School Run', 'Market Run', 'Charter', 'Night Route'],
                        [['Fares', 520000, 400], ['Parcels', 180000, 150], ['Charter', 300000, 200], ['School contract', 250000, 0], ['Baggage', 40000, 20], ['Night fare', 90000, 50]],
                    ),
                    '5000' => $this->book(
                        ['Terminal Fee', 'Fuel Stop', 'Drivers', 'Mechanic', 'Insurance Desk', 'Bay Telecom'],
                        [['Terminal', 300000, 0], ['Fuel', 900000, 4000], ['Wages', 1100000, 0], ['Repairs', 250000, 500], ['Insurance', 180000, 0], ['Radio', 49900, 0]],
                    ),
                    '5100' => $this->book(
                        ['Parts Counter', 'Tire Shop', 'Battery Depot', 'Oil Dealer', 'Body Shop', 'Cleaners'],
                        [['Tires', 160000, 100], ['Oil', 70000, 80], ['Batteries', 90000, 50], ['Filters', 25000, 20], ['Belts', 30000, 20], ['Cleaner', 15000, 10]],
                    ),
                ],
            ],
            [
                'category' => 'Salon',
                'name' => 'Shear House',
                'description' => 'Haircuts, color, and grooming.',
                'books' => [
                    '4000' => $this->book(
                        ['Walk-in', 'Wedding Party', 'School Cut', 'Regulars', 'Home Service', 'Color Booking'],
                        [['Haircut', 240000, 150], ['Color', 320000, 250], ['Treatment', 180000, 120], ['Shave', 60000, 20], ['Home service', 150000, 80], ['Products sold', 80000, 40]],
                    ),
                    '5000' => $this->book(
                        ['Salon Lease', 'City Power', 'Metro Water', 'Stylists', 'Bay Telecom', 'Laundry'],
                        [['Rent', 1000000, 0], ['Electricity', 220000, 1000], ['Water', 40000, 200], ['Wages', 950000, 0], ['Internet', 129900, 0], ['Towel laundry', 60000, 0]],
                    ),
                    '5100' => $this->book(
                        ['Beauty Wholesale', 'Color Lab', 'Blade Supply', 'Towel Mill', 'Product Depot', 'Cape Maker'],
                        [['Color', 120000, 150], ['Shampoo', 70000, 60], ['Blades', 18000, 10], ['Towels', 25000, 20], ['Products', 90000, 80], ['Capes', 15000, 10]],
                    ),
                ],
            ],
            [
                'category' => 'Poultry',
                'name' => 'Farm Lane',
                'description' => 'Eggs and dressed chicken from a small farm.',
                'books' => [
                    '4000' => $this->book(
                        ['Market Stall', 'Bakeries', 'Eateries', 'Grocery Route', 'Walk-in Farm', 'Hotel Kitchen'],
                        [['Eggs', 480000, 600], ['Dressed chicken', 360000, 500], ['Live birds', 220000, 200], ['Manure', 20000, 0], ['Chicks', 80000, 50], ['Trays', 30000, 10]],
                    ),
                    '5000' => $this->book(
                        ['Farm Lot', 'City Power', 'Deep Well', 'Farm Hands', 'Vet Visit', 'Hauling'],
                        [['Lot', 500000, 0], ['Electricity', 180000, 800], ['Water', 70000, 300], ['Wages', 750000, 0], ['Veterinary', 120000, 0], ['Hauling', 160000, 400]],
                    ),
                    '5100' => $this->book(
                        ['Feed Mill', 'Chick Supplier', 'Medicine Co-op', 'Tray Maker', 'Bedding Yard', 'Vitamin Depot'],
                        [['Feeds', 320000, 400], ['Chicks', 140000, 100], ['Medicine', 60000, 40], ['Trays', 20000, 10], ['Rice hull', 15000, 10], ['Vitamins', 35000, 20]],
                    ),
                ],
            ],
            [
                'category' => 'Printing',
                'name' => 'Press Room',
                'description' => 'Print jobs, tarpaulins, and school documents.',
                'books' => [
                    '4000' => $this->book(
                        ['School', 'Campaign Office', 'Shop Owners', 'Walk-in', 'Church', 'Office Account'],
                        [['Documents', 220000, 150], ['Tarpaulin', 400000, 300], ['Invitations', 160000, 100], ['Shirts', 280000, 200], ['Stickers', 70000, 40], ['Binding', 50000, 20]],
                    ),
                    '5000' => $this->book(
                        ['Shop Lease', 'City Power', 'Press Operators', 'Machine Service', 'Bay Telecom', 'Delivery'],
                        [['Rent', 1100000, 0], ['Electricity', 390000, 2200], ['Wages', 800000, 0], ['Repairs', 150000, 0], ['Internet', 149900, 0], ['Delivery', 80000, 200]],
                    ),
                    '5100' => $this->book(
                        ['Paper Mill', 'Ink House', 'Tarpaulin Roll', 'Shirt Blanks', 'Plate Maker', 'Lamination'],
                        [['Paper', 180000, 200], ['Ink', 140000, 150], ['Tarpaulin', 160000, 180], ['Shirts', 120000, 100], ['Plates', 40000, 20], ['Film', 30000, 20]],
                    ),
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $parties
     * @param  list<array{0: string, 1: int, 2: int}>  $items
     * @return array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}
     */
    private function book(array $parties, array $items): array
    {
        return [
            'parties' => $parties,
            'items' => array_map(fn (array $item): array => [
                'name' => $item[0],
                'unit_cents' => $item[1],
                'step' => $item[2],
            ], $items),
        ];
    }

    /**
     * @return array<string, array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}>
     */
    private function generalBooks(): array
    {
        return [
            '4000' => $this->book(
                ['Walk-in', 'Regular Account', 'Delivery', 'Counter'],
                [['Goods', 200000, 200], ['Services', 150000, 100], ['Fees', 50000, 20]],
            ),
            '5000' => $this->book(
                ['Landlord', 'City Power', 'Staff', 'Bay Telecom'],
                [['Rent', 800000, 0], ['Electricity', 180000, 800], ['Wages', 600000, 0], ['Internet', 99900, 0]],
            ),
            '5100' => $this->book(
                ['Wholesale', 'Supplier', 'Market'],
                [['Supplies', 120000, 150], ['Materials', 80000, 80], ['Packaging', 20000, 10]],
            ),
        ];
    }

    /**
     * @return array<string, array{parties: list<string>, items: list<array{name: string, unit_cents: int, step: int}>}>
     */
    private function harborBooks(): array
    {
        return [
            '4000' => $this->book(
                ['Harbor Market', 'Northwind Trading', 'Green Basket', 'Pier Cafe', 'South Dock Store', 'Lina Cruz'],
                [['Rice', 5000, 25], ['Sugar', 2500, 15], ['Cooking oil', 8500, 40], ['Coffee', 12000, 50], ['Soap', 3500, 10], ['Eggs', 1800, 20]],
            ),
            '5000' => $this->book(
                ['Harbor Rentals', 'City Power', 'Metro Water', 'Fuel Stop', 'Bay Telecom', 'Rosa Santos'],
                [['Rent', 1500000, 0], ['Electricity', 420000, 3500], ['Water', 85000, 500], ['Fuel', 250000, 2000], ['Internet', 199900, 0], ['Wages', 800000, 0]],
            ),
            '5100' => $this->book(
                ['Northwind Supplies', 'Eastmill Grains', 'Cebu Mill', 'Harbor Market', 'West Wharf Trading', 'Palengke Wholesale'],
                [['Rice', 145000, 200], ['Sugar', 32000, 100], ['Flour', 28000, 80], ['Cooking oil', 9600, 40], ['Vinegar', 4500, 15], ['Salt', 1800, 10]],
            ),
        ];
    }
}
