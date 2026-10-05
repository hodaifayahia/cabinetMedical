<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(['ordonnance', 'bilan', 'courrier']),
            'group' => 'Mes modèles',
            'title' => fake()->unique()->words(3, true),
            'body' => "## {{patient.full_name}}\n\n{{consultation.diagnostic}}",
            'paper_size' => fake()->randomElement(['A4', 'A5']),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function category(string $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }
}
