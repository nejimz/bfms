<?php

namespace Database\Factories;

use App\Models\InventoryAdjustment;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAdjustment>
 */
class InventoryAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'date' => '2026-01-15',
            'quantity' => '-1',
            'note' => fake()->optional()->sentence(),
        ];
    }
}
