<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/me — current principal + effective permissions.
 *
 * Requires PostgreSQL test database (schema triggers).
 */
final class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_authenticated_user_and_permissions(): void
    {
        $user = $this->seedCompanyAndUser(['ledger.read', 'admin.users']);

        $response = $this->omnisGet($user, '/api/v1/me');

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonPath('data.companyId', $user->company_id);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'email',
                'displayName',
                'companyId',
                'permissions',
            ],
        ]);

        $permissions = $response->json('data.permissions');
        $this->assertContains('ledger.read', $permissions);
        $this->assertContains('admin.users', $permissions);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'unauthenticated');
    }
}
