<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/stock/on-hand — derived balance query.
 */
final class StockOnHandTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_hand_requires_permission(): void
    {
        $user = $this->seedCompanyAndUser([]);

        $response = $this->omnisGet($user, '/api/v1/stock/on-hand');
        $this->assertErrorCode($response, 'forbidden', 403);
    }

    public function test_on_hand_returns_list_when_permitted(): void
    {
        $user = $this->seedCompanyAndUser(['stock.view']);

        $response = $this->omnisGet($user, '/api/v1/stock/on-hand');

        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }
}
