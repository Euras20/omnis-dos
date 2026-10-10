<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Exceptions\PeriodLockedException;
use App\Models\Company;
use App\Shared\Company\CompanyContext;
use App\Shared\PeriodLock\PeriodLockChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PeriodLockChecker — pure rule + company.period_lock_date.
 *
 * Uses RefreshDatabase so company row can be read; still requires PG for full suite.
 */
final class PeriodLockCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_date_passes(): void
    {
        $company = Company::factory()->create(['period_lock_date' => '2026-05-31']);
        $this->bindCompanyContext($company->id);

        $checker = app(PeriodLockChecker::class);
        $checker->assertOpen('2026-06-01');
        $this->assertTrue(true);
    }

    public function test_locked_date_throws(): void
    {
        $company = Company::factory()->create(['period_lock_date' => '2026-06-30']);
        $this->bindCompanyContext($company->id);

        $checker = app(PeriodLockChecker::class);

        $this->expectException(PeriodLockedException::class);
        $checker->assertOpen('2026-06-15');
    }

    public function test_null_lock_allows_any_date(): void
    {
        $company = Company::factory()->create(['period_lock_date' => null]);
        $this->bindCompanyContext($company->id);

        $checker = app(PeriodLockChecker::class);
        $checker->assertOpen('2020-01-01');
        $this->assertTrue(true);
    }

    private function bindCompanyContext(string $companyId): void
    {
        $ctx = app(CompanyContext::class);
        $ctx->set($companyId, (string) \Illuminate\Support\Str::uuid(), 'test-request');
    }
}
