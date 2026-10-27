<?php

namespace Database\Factories;

use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'actor_name' => fake()->name(),
            'module' => ActivityModule::Category,
            'action' => ActivityAction::Added,
            'record_name' => fake()->words(2, true),
        ];
    }
}
