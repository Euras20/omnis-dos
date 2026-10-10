<?php

declare(strict_types=1);

namespace App\Modules\Payables\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves standard account codes used by AP posting to account UUIDs.
 */
final class AccountResolver
{
    private const CODES = [
        'ap' => '2000',
        'expense' => '5000',
        'claimable_tax' => '1400',
        'wht_payable' => '2320',
        'cash' => '1000',
        'grni' => '2050',
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
}
