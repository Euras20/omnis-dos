<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Exceptions\ForbiddenException;
use App\Models\AppUser;
use App\Models\Company;
use App\Models\Role;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PermissionCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_grant_allows_permission(): void
    {
        $company = Company::factory()->create();
        $user = AppUser::factory()->forCompany($company)->create();
        $role = Role::factory()->forCompany($company)->create();

        DB::table('role_permission')->insert([
            'role_id' => $role->id,
            'permission' => 'ledger.read',
        ]);
        DB::table('user_role')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checker = app(PermissionChecker::class);
        $this->assertTrue($checker->can($user->id, 'ledger.read'));
        $this->assertFalse($checker->can($user->id, 'admin.users'));
    }

    public function test_deny_override_wins(): void
    {
        $company = Company::factory()->create();
        $user = AppUser::factory()->forCompany($company)->create();
        $role = Role::factory()->forCompany($company)->create();

        DB::table('role_permission')->insert([
            'role_id' => $role->id,
            'permission' => 'ledger.journal.post',
        ]);
        DB::table('user_role')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_override')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'permission' => 'ledger.journal.post',
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checker = app(PermissionChecker::class);
        $this->assertFalse($checker->can($user->id, 'ledger.journal.post'));
    }

    public function test_assert_can_throws_forbidden(): void
    {
        $company = Company::factory()->create();
        $user = AppUser::factory()->forCompany($company)->create();

        $checker = app(PermissionChecker::class);
        $this->expectException(ForbiddenException::class);
        $checker->assertCan($user->id, 'admin.roles');
    }
}
