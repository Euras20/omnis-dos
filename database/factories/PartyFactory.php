<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'party_type' => 'customer',
            'code' => strtoupper($this->faker->unique()->bothify('C###')),
            'name' => $this->faker->company(),
            'tin' => null,
            'email' => $this->faker->companyEmail(),
            'phone' => null,
            'address' => null,
            'currency' => 'GHS',
            'payment_terms' => 'Net 30',
            'credit_limit_minor' => null,
            'is_active' => true,
            'row_version' => 1,
        ];
    }

    public function forCompany(Company|string $company): static
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $this->state(fn () => ['company_id' => $id]);
    }

    public function customer(): static
    {
        return $this->state(fn () => ['party_type' => 'customer']);
    }

    public function supplier(): static
    {
        return $this->state(fn () => [
            'party_type' => 'supplier',
            'code' => strtoupper($this->faker->unique()->bothify('S###')),
        ]);
    }
}
