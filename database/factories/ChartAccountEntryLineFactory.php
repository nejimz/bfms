<?php

namespace Database\Factories;

use App\Models\ChartAccountEntry;
use App\Models\ChartAccountEntryLine;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartAccountEntryLine>
 */
class ChartAccountEntryLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'chart_account_entry_id' => function (array $attributes) {
                return ChartAccountEntry::factory()->create([
                    'company_id' => $attributes['company_id'],
                ])->id;
            },
            'position' => 0,
            'name' => 'Rice',
            'quantity' => '1.000',
            'amount_cents' => 5000,
        ];
    }
}
