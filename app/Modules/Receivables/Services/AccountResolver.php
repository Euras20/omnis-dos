<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves standard account codes used by AR posting to account UUIDs.
 * Codes from 02-database.md posting map.
 */
final class AccountResolver
{
    private const CODES = [
        'ar' => '1100',
        'advances' => '2100',
        'revenue' => '4000',
        'vat' => '2200',
        'nhil' => '2210',
        'getfund' => '2220',
        'covid' => '2230',
        'wht_receivable' => '1420',
        'cash' => '1000',
    ];

    public function idByCode(string $companyId, string $logical): string
    {
        $code = self::CODES[$logical] ?? $logical;

        $key = "omnis.account.{$companyId}.{$code}";
        $id = Cache::store('array')->get($key);
        if (is_string($id)) {
            return $id;
        }

        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw new BusinessRuleException("Account code '{$code}' is not configured for this company.");
        }

        Cache::store('array')->put($key, $account->id, 60);

        return $account->id;
    }

    public function idByCashCode(string $companyId, string $cashCode): string
    {
        return $this->idByCode($companyId, $cashCode);
    }
}
