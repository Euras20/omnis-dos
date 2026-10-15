<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches mid-market FX rates and applies a selling markup.
 *
 * Production is expected to use the official XE Currency Data API
 * (https://xecdapi.xe.com) when XE_ACCOUNT_ID / XE_API_KEY are configured.
 * Until credentials are supplied the service falls back to a free mid-market
 * source so the morning rate capture still works. The stored source string
 * always indicates the origin and the applied markup.
 */
final class XeFxRateService
{
    private const SELL_MARKUP_PERCENT = 3.0;

    /**
     * @param  list<string>  $currencies
     * @return list<array{currency: string, midRate: float, buyRate: float, sellRate: float, source: string}>
     */
    public function fetch(array $currencies = ['USD', 'EUR', 'CNY']): array
    {
        $currencies = array_values(array_unique(array_map('strtoupper', $currencies)));
        $currencies = array_filter($currencies, fn (string $c) => $c !== 'GHS' && strlen($c) === 3);

        if ($currencies === []) {
            throw new BusinessRuleException('At least one foreign currency is required.');
        }

        $midRates = $this->fetchMidRates($currencies);
        $markup = 1 + (self::SELL_MARKUP_PERCENT / 100);
        $source = $this->sourceLabel();

        $result = [];
        foreach ($currencies as $currency) {
            if (! isset($midRates[$currency]) || $midRates[$currency] <= 0) {
                continue;
            }

            $mid = (float) $midRates[$currency];
            $result[] = [
                'currency' => $currency,
                'midRate' => $mid,
                'buyRate' => $mid,                 // mid used as buying reference
                'sellRate' => $mid * $markup,      // 3% selling markup
                'source' => $source,
            ];
        }

        if ($result === []) {
            throw new BusinessRuleException('No rates returned for the requested currencies.');
        }

        return $result;
    }

    /**
     * @param  list<string>  $currencies
     * @return array<string, float> currency => GHS per 1 unit
     */
    private function fetchMidRates(array $currencies): array
    {
        if ($this->hasXeCredentials()) {
            return $this->fetchFromXe($currencies);
        }

        return $this->fetchFromPublicMidMarket($currencies);
    }

    private function hasXeCredentials(): bool
    {
        return filled(config('omnis.xe.account_id')) && filled(config('omnis.xe.api_key'));
    }

    /**
     * Official XE Currency Data API (paid).
     * Docs: https://xecdapi.xe.com/docs/v1
     *
     * @param  list<string>  $currencies
     * @return array<string, float>
     */
    private function fetchFromXe(array $currencies): array
    {
        $accountId = (string) config('omnis.xe.account_id');
        $apiKey = (string) config('omnis.xe.api_key');
        $base = rtrim((string) config('omnis.xe.base_url', 'https://xecdapi.xe.com/v1'), '/');

        $response = Http::withBasicAuth($accountId, $apiKey)
            ->timeout(15)
            ->get($base.'/convert_from.json', [
                'from' => 'USD',
                'to' => implode(',', array_merge($currencies, ['GHS'])),
                'amount' => 1,
            ]);

        if (! $response->successful()) {
            Log::warning('XE FX fetch failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new BusinessRuleException('XE.com rate service is unavailable. Enter rates manually or retry later.');
        }

        $data = $response->json();
        $quotes = [];
        foreach ($data['to'] ?? [] as $row) {
            $quotes[(string) $row['quotecurrency']] = (float) $row['mid'];
        }

        // XE returns amount of quote currency per 1 USD. Convert to GHS per 1 unit of target.
        $ghsPerUsd = $quotes['GHS'] ?? null;
        if ($ghsPerUsd === null || $ghsPerUsd <= 0) {
            throw new BusinessRuleException('XE.com did not return a GHS rate.');
        }

        $result = [];
        foreach ($currencies as $currency) {
            if ($currency === 'USD') {
                $result['USD'] = $ghsPerUsd;
                continue;
            }
            $unitsPerUsd = $quotes[$currency] ?? null;
            if ($unitsPerUsd && $unitsPerUsd > 0) {
                $result[$currency] = $ghsPerUsd / $unitsPerUsd;
            }
        }

        return $result;
    }

    /**
     * Free public mid-market source used when XE credentials are not configured.
     * Rates are still stored with a clear source label so operators know the origin.
     *
     * @param  list<string>  $currencies
     * @return array<string, float>
     */
    private function fetchFromPublicMidMarket(array $currencies): array
    {
        $response = Http::timeout(15)
            ->get('https://open.er-api.com/v6/latest/USD');

        if (! $response->successful()) {
            throw new BusinessRuleException('Public mid-market rate service is unavailable. Enter rates manually.');
        }

        $rates = $response->json('rates') ?? [];
        $ghsPerUsd = $rates['GHS'] ?? null;
        if ($ghsPerUsd === null || $ghsPerUsd <= 0) {
            throw new BusinessRuleException('Public rate service did not return a GHS rate.');
        }

        $result = [];
        foreach ($currencies as $currency) {
            if ($currency === 'USD') {
                $result['USD'] = (float) $ghsPerUsd;
                continue;
            }
            $unitsPerUsd = $rates[$currency] ?? null;
            if ($unitsPerUsd && $unitsPerUsd > 0) {
                $result[$currency] = (float) $ghsPerUsd / (float) $unitsPerUsd;
            }
        }

        return $result;
    }

    private function sourceLabel(): string
    {
        $origin = $this->hasXeCredentials() ? 'XE.com' : 'mid-market (XE-compatible)';

        return sprintf('%s mid + %.0f%% sell markup', $origin, self::SELL_MARKUP_PERCENT);
    }
}
