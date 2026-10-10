<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'code' => strtoupper($this->faker->unique()->lexify('ROLE_???')),
            'name' => $this->faker->jobTitle().' Role',
            'description' => null,
            'approval_limit_minor' => null,
            'is_system' => false,
            'is_active' => true,
            'row_version' => 1,
        ];
    }

    public function forCompany(Company|string $company): static
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $this->state(fn () => ['company_id' => $id]);
    }

    public function system(string $code = 'ADMIN'): static
    {
        return $this->state(fn () => [
            'code' => $code,
            'name' => $code,
            'is_system' => true,
        ]);
    }
}
