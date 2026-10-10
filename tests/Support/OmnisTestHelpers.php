<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AppUser;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared helpers for OMNIS Feature / Integration tests.
 *
 * Auth uses the `dev` driver + X-Dev-User header (local/testing only).
 * Company context is resolved from the authenticated user's company_id.
 */
trait OmnisTestHelpers
{
    protected ?Company $testCompany = null;

    protected ?AppUser $testUser = null;

    /**
     * Create a company + active staff user, optionally grant permissions via a role.
     *
     * @param  list<string>  $permissions
     */
    protected function seedCompanyAndUser(array $permissions = [], ?string $periodLockDate = null): AppUser
    {
        $companyAttrs = [];
        if ($periodLockDate !== null) {
            $companyAttrs['period_lock_date'] = $periodLockDate;
        }

        $this->testCompany = Company::factory()->create($companyAttrs);

        $this->testUser = AppUser::factory()->forCompany($this->testCompany)->create([
            'email' => 'tester@omnis.test',
            'display_name' => 'Test User',
        ]);

        if ($permissions !== []) {
            $this->grantPermissions($this->testUser, $permissions);
        }

        return $this->testUser;
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function grantPermissions(AppUser $user, array $permissions): void
    {
        $role = Role::factory()->forCompany($user->company_id)->create([
            'code' => 'TEST_'.Str::upper(Str::random(6)),
            'name' => 'Test Role',
        ]);

        foreach ($permissions as $code) {
            DB::table('role_permission')->insert([
                'role_id' => $role->id,
                'permission' => $code,
            ]);
        }

        DB::table('user_role')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Issue an authenticated JSON request with dev auth + optional idempotency key.
     */
    protected function actingAsOmnis(
        AppUser $user,
        string $method,
        string $uri,
        array $data = [],
        ?string $idempotencyKey = null,
        array $headers = [],
    ): TestResponse {
        $headers = array_merge([
            'X-Dev-User' => $user->email,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $headers);

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withHeaders($headers)->json($method, $uri, $data);
    }

    protected function omnisGet(AppUser $user, string $uri, array $headers = []): TestResponse
    {
        return $this->actingAsOmnis($user, 'GET', $uri, [], null, $headers);
    }

    protected function omnisPost(
        AppUser $user,
        string $uri,
        array $data = [],
        ?string $idempotencyKey = null,
        array $headers = [],
    ): TestResponse {
        return $this->actingAsOmnis(
            $user,
            'POST',
            $uri,
            $data,
            $idempotencyKey ?? (string) Str::uuid(),
            $headers,
        );
    }

    protected function omnisPatch(
        AppUser $user,
        string $uri,
        array $data = [],
        ?string $idempotencyKey = null,
        array $headers = [],
    ): TestResponse {
        return $this->actingAsOmnis(
            $user,
            'PATCH',
            $uri,
            $data,
            $idempotencyKey ?? (string) Str::uuid(),
            $headers,
        );
    }

    protected function assertErrorCode(TestResponse $response, string $code, int $status): void
    {
        $response->assertStatus($status);
        $response->assertJsonPath('error.code', $code);
    }

    protected function assertAuditEventExists(string $companyId, string $action, ?string $entityId = null): void
    {
        $query = DB::table('audit_event')
            ->where('company_id', $companyId)
            ->where('action', $action);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        $this->assertTrue($query->exists(), "Expected audit_event action={$action}");
    }

    protected function assertOutboxEventExists(string $companyId, string $eventType): void
    {
        $this->assertTrue(
            DB::table('outbox')
                ->where('company_id', $companyId)
                ->where('event_type', $eventType)
                ->where('status', 'pending')
                ->exists(),
            "Expected pending outbox event_type={$eventType}",
        );
    }

    protected function assertJournalBalanced(string $journalId): void
    {
        $row = DB::table('journal_line')
            ->where('journal_id', $journalId)
            ->selectRaw('COALESCE(SUM(debit_minor),0) as debits, COALESCE(SUM(credit_minor),0) as credits')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(
            (int) $row->debits,
            (int) $row->credits,
            "Journal {$journalId} is not balanced",
        );
    }

    protected function assertNoJournalForSource(string $companyId, string $sourceType, string $sourceId): void
    {
        $this->assertFalse(
            DB::table('journal')
                ->where('company_id', $companyId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->exists(),
            'Unexpected journal written for source',
        );
    }
}
