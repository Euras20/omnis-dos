<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reminds that depreciation has not been posted for the current period.
 *
 * Does NOT auto-post. Depreciation remains a user-initiated SoD command.
 * Cadence: monthly (e.g. 25th of each month).
 */
final class DepreciationDueReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $periodMonth = now()->format('Y-m');

        if (! DB::getSchemaBuilder()->hasTable('depreciation_run')) {
            return;
        }

        $companies = DB::table('company')->select('id')->get();

        foreach ($companies as $company) {
            $hasRun = DB::table('depreciation_run')
                ->where('company_id', $company->id)
                ->where('period_month', $periodMonth)
                ->whereIn('status', ['posted', 'approved'])
                ->exists();

            if ($hasRun) {
                continue;
            }

            // Only remind if the company has capitalised assets.
            $hasAssets = DB::table('fixed_asset')
                ->where('company_id', $company->id)
                ->exists();

            if (! $hasAssets) {
                continue;
            }

            DB::table('outbox')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'aggregate_type' => 'depreciation',
                'aggregate_id' => $company->id,
                'event_type' => 'depreciation.due_reminder',
                'payload' => json_encode([
                    'companyId' => $company->id,
                    'periodMonth' => $periodMonth,
                    'message' => "Depreciation not yet posted for {$periodMonth}",
                ], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
            ]);
        }

        Log::info('DepreciationDueReminder emitted', ['period_month' => $periodMonth]);
    }
}
