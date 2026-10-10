<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'legal_name' => $this->faker->company().' Ltd',
            'tin' => $this->faker->numerify('C##########'),
            'functional_currency' => 'GHS',
            'fiscal_year_start_month' => 1,
            'timezone' => 'Africa/Accra',
            'period_lock_date' => null,
            'address' => $this->faker->address(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->companyEmail(),
            'row_version' => 1,
        ];
    }

    public function withPeriodLock(string $date): static
    {
        return $this->state(fn () => ['period_lock_date' => $date]);
    }
}
