<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Actions\FetchXeExchangeRates;
use App\Shared\Company\CompanyContext;
use Illuminate\Console\Command;

/**
 * omnis:fx-fetch-xe
 *
 * Fetches XE.com (or mid-market) rates and stores them with a 3% selling markup.
 * Intended to be scheduled for the morning rate capture.
 */
final class FetchXeRatesCommand extends Command
{
    protected $signature = 'omnis:fx-fetch-xe
                            {--date= : Rate date (YYYY-MM-DD). Defaults to today.}
                            {--currencies= : Comma-separated codes. Defaults to the frontend currency list.}';

    protected $description = 'Fetch mid-market FX rates and store them with a 3% selling markup';

    public function handle(FetchXeExchangeRates $action, CompanyContext $companyContext): int
    {
        // Console runs without an authenticated user; the action expects a company context.
        // Operators run this under a company that has already been selected via the normal
        // company resolution path, or the command can be extended to accept --company.
        if (! $companyContext->companyId()) {
            $this->error('No company context. Run inside a company-scoped process or extend the command with --company.');

            return self::FAILURE;
        }

        $date = $this->option('date') ?: date('Y-m-d');
        $currencies = $this->option('currencies')
            ? array_filter(array_map('trim', explode(',', (string) $this->option('currencies'))))
            : [];

        $result = $action->execute([
            'date' => $date,
            ...($currencies !== [] ? ['currencies' => $currencies] : []),
        ]);

        $this->info('Stored '.count($result['rates']).' rates for '.$result['date']);
        foreach ($result['rates'] as $rate) {
            $this->line(sprintf(
                '  %s  mid %s  sell %s  (%s)',
                $rate['currency'],
                number_format((float) $rate['midRate'], 4),
                number_format((float) $rate['sellRate'], 4),
                $rate['source']
            ));
        }

        return self::SUCCESS;
    }
}
