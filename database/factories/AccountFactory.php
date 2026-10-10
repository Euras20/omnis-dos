<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $code = (string) $this->faker->unique()->numberBetween(1000, 9999);

        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'code' => $code,
            'name' => $this->faker->words(3, true),
            'category' => 'asset',
            'kind' => 'detail',
            'normal_balance' => 'debit',
            'currency' => null,
            'is_active' => true,
            'fs_line_item' => null,
            'fs_sub_line' => null,
            'row_version' => 1,
        ];
    }

    public function forCompany(Company|string $company): static
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $this->state(fn () => ['company_id' => $id]);
    }

    public function withCode(string $code): static
    {
        return $this->state(fn () => ['code' => $code]);
    }

    public function liability(): static
    {
        return $this->state(fn () => [
            'category' => 'liability',
            'normal_balance' => 'credit',
        ]);
    }

    public function revenue(): static
    {
        return $this->state(fn () => [
            'category' => 'income',
            'normal_balance' => 'credit',
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn () => [
            'category' => 'expense',
            'normal_balance' => 'debit',
        ]);
    }
}
