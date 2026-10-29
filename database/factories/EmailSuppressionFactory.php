<?php

namespace Database\Factories;

use App\Enums\EmailSuppressionReason;
use App\Models\EmailSuppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSuppression>
 */
class EmailSuppressionFactory extends Factory
{
    protected $model = EmailSuppression::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'reason' => EmailSuppressionReason::Manual,
        ];
    }
}
