<?php

namespace Database\Factories;

use App\Enums\ChartAccountEntryStatus;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartAccountEntry>
 */
class ChartAccountEntryFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ChartAccountEntry $entry): void {
            if (! array_key_exists('cash_cents', $entry->getAttributes())) {
                $entry->cash_cents = $entry->amount_cents;
            }

            if (! array_key_exists('credit_cents', $entry->getAttributes())) {
                $entry->credit_cents = 0;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'account_id' => function (array $attributes) {
                return Account::factory()->create([
                    'company_id' => $attributes['company_id'],
                ])->id;
            },
            'date' => '2026-10-15',
            'particulars' => fake()->sentence(),
            'amount_cents' => 10000,
            'currency' => 'PHP',
            'status' => ChartAccountEntryStatus::Draft,
            'created_by' => User::factory(),
        ];
    }
}
