<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Models\Account;
use App\Models\JournalLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/accounts — chart of accounts with posted balances.
 */
final class ListAccountsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.read');

        $companyId = $this->companyContext->companyId();
        $asOf = isset($filters['asOf']) && is_string($filters['asOf']) ? $filters['asOf'] : null;

        $query = Account::query()
            ->where('company_id', $companyId)
            ->orderBy('code');

        if (isset($filters['category']) && is_string($filters['category'])) {
            $query->where('category', strtolower($filters['category']));
        }
        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('is_active', true);
            } elseif ($filters['status'] === 'inactive') {
                $query->where('is_active', false);
            }
        }
        if (isset($filters['q']) && is_string($filters['q']) && $filters['q'] !== '') {
            $q = '%'.$filters['q'].'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('code', 'ilike', $q)
                    ->orWhere('name', 'ilike', $q);
            });
        }

        $total = (clone $query)->count();
        $limit = min(max((int) ($filters['limit'] ?? 200), 1), 500);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $accounts = $query->limit($limit)->offset($offset)->get();

        // Posted balances from journal lines
        $balanceQuery = JournalLine::query()
            ->select([
                'journal_line.account_id',
                DB::raw('COALESCE(SUM(journal_line.debit_minor), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_line.credit_minor), 0) as total_credit'),
            ])
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->groupBy('journal_line.account_id');

        if ($asOf !== null) {
            $balanceQuery->where('journal.posting_date', '<=', $asOf);
        }

        $balances = $balanceQuery->get()->keyBy('account_id');

        $items = [];
        foreach ($accounts as $account) {
            $bal = $balances->get($account->id);
            $debit = $bal ? (int) $bal->total_debit : 0;
            $credit = $bal ? (int) $bal->total_credit : 0;
            // Net in normal-balance direction
            $balanceMinor = $account->normal_balance === 'credit'
                ? $credit - $debit
                : $debit - $credit;

            $items[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
                'kind' => $account->kind,
                'currency' => $account->currency,
                'isActive' => $account->is_active,
                'fsLineItem' => $account->fs_line_item,
                'fsSubLine' => $account->fs_sub_line,
                'normalBalance' => $account->normal_balance,
                'balanceMinor' => $balanceMinor,
                'debitMinor' => $debit,
                'creditMinor' => $credit,
                'rowVersion' => $account->row_version,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
