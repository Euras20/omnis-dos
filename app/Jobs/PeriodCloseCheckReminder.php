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
 * Emits outbox rows to remind approvers that period-close checks are outstanding.
 *
 * Does NOT post or lock anything — user-initiated close remains the only path.
 * Cadence: configurable before month-end (default: daily in the last 5 days of month).
 */
final class PeriodCloseCheckReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $periodMonth = now()->format('Y-m');
        $dayOfMonth = (int) now()->format('d');
        $daysInMonth = (int) now()->format('t');

        // Only fire in the last 5 calendar days of the month.
        if ($dayOfMonth < $daysInMonth - 4) {
            return;
        }

        $companies = DB::table('company')->select('id')->get();

        foreach ($companies as $company) {
            // Skip if already closed for this period (period_close table may not exist yet;
            // treat missing as "not closed").
            $alreadyClosed = false;
            if (DB::getSchemaBuilder()->hasTable('period_close')) {
                $alreadyClosed = DB::table('period_close')
                    ->where('company_id', $company->id)
                    ->where('period_month', $periodMonth)
                    ->where('status', 'closed')
                    ->exists();
            }

            if ($alreadyClosed) {
                continue;
            }

            DB::table('outbox')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'aggregate_type' => 'period',
                'aggregate_id' => $company->id,
                'event_type' => 'period.close_reminder',
                'payload' => json_encode([
                    'companyId' => $company->id,
                    'periodMonth' => $periodMonth,
                    'message' => "Period close checks outstanding for {$periodMonth}",
                ], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
            ]);
        }

        Log::info('PeriodCloseCheckReminder emitted', [
            'period_month' => $periodMonth,
            'company_count' => $companies->count(),
        ]);
    }
}
