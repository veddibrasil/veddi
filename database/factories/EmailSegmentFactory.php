<?php

namespace Database\Factories;

use App\Models\EmailSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSegment>
 */
class EmailSegmentFactory extends Factory
{
    protected $model = EmailSegment::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'filters' => [],
        ];
    }
}
