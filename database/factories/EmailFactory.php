<?php

namespace Database\Factories;

use App\Models\Email;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Email>
 */
class EmailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gmail_id' => fake()->unique()->uuid(),
            'thread_id' => fake()->uuid(),
            'sender_email' => fake()->safeEmail(),
            'sender_domain' => fake()->domainName(),
            // Real content (encrypted via the model cast on persist).
            'subject_enc' => 'Project update',
            'body_enc' => 'Hi, here is the latest on the project.',
            'received_at' => now(),
        ];
    }

    /**
     * Mark the email as already anonymized — used to exercise the jobs'
     * idempotent skip paths.
     */
    public function anonymized(): static
    {
        return $this->state(fn (array $attributes) => [
            'anonymized_subject' => 'Project update',
            'anonymized_body' => 'Hi, here is the latest on [PROJECT_1].',
            'is_anonymized' => true,
            'anonymized_at' => now(),
        ]);
    }
}
