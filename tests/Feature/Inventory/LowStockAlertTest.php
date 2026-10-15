<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/stock/low-stock — in-system low-stock alert (50% of reorder point).
 */
final class LowStockAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_requires_permission(): void
    {
        $user = $this->seedCompanyAndUser([]);

        $response = $this->omnisGet($user, '/api/v1/stock/low-stock');
        $this->assertErrorCode($response, 'forbidden', 403);
    }

    public function test_low_stock_returns_structure_when_permitted(): void
    {
        $user = $this->seedCompanyAndUser(['stock.view']);

        $response = $this->omnisGet($user, '/api/v1/stock/low-stock');

        $response->assertOk();
        $response->assertJsonStructure([
            'items',
            'total',
            'thresholdPercent',
        ]);
        $response->assertJson(['thresholdPercent' => 50]);
    }
}
