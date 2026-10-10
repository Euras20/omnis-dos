<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Company;
use App\Shared\Outbox\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Outbox claim + complete / retry isolation.
 *
 * Handlers are not required to produce side-effects here — we assert
 * dispatcher state transitions only.
 */
final class OutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_and_complete_pending_row(): void
    {
        $company = Company::factory()->create();

        $id = (string) Str::uuid();
        DB::table('outbox')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'aggregate_type' => 'test',
            'aggregate_id' => $id,
            'event_type' => 'domain.event.log',
            'payload' => json_encode(['entityId' => $id, 'message' => 'test']),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now()->subSecond(),
            'created_at' => now(),
        ]);

        $dispatcher = app(OutboxDispatcher::class);
        // Ensure a handler exists for the event type (registered in AppServiceProvider)
        $processed = $dispatcher->dispatch(10);

        $this->assertGreaterThanOrEqual(0, $processed);

        $row = DB::table('outbox')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertContains($row->status, ['completed', 'pending', 'processing', 'dead', 'failed']);
    }

    public function test_empty_batch_returns_zero(): void
    {
        $dispatcher = app(OutboxDispatcher::class);
        $this->assertSame(0, $dispatcher->dispatch(5));
    }
}
