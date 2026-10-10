<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppUser;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppUser>
 */
class AppUserFactory extends Factory
{
    protected $model = AppUser::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'display_name' => $this->faker->name(),
            'external_subject' => 'dev|'.Str::uuid()->toString(),
            'user_type' => 'staff',
            'party_id' => null,
            'employee_id' => null,
            'is_active' => true,
            'phone' => null,
            'job_title' => null,
            'created_by' => null,
            'last_seen_at' => null,
            'deactivated_at' => null,
            'deactivation_reason' => null,
            'row_version' => 1,
        ];
    }

    public function forCompany(Company|string $company): static
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $this->state(fn () => ['company_id' => $id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'test',
        ]);
    }

    public function portal(): static
    {
        return $this->state(fn () => ['user_type' => 'portal']);
    }
}
