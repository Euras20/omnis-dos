<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Queries;

use App\Models\BankReconciliation;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/bank/reconciliations — history of prepared/approved reconciliations.
 */
final class ListBankReconciliationsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{bankAccountId?: ?string, limit?: mixed}  $filters
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(array $filters): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.view');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);

        $query = BankReconciliation::query()->where('company_id', $companyId);
        if (! empty($filters['bankAccountId'])) {
            $query->where('bank_account_id', (string) $filters['bankAccountId']);
        }

        $rows = $query->orderByDesc('statement_date')->orderByDesc('prepared_at')->limit($limit)->get();

        $items = $rows->map(fn (BankReconciliation $r) => [
            'id' => $r->id,
            'bankAccountId' => $r->bank_account_id,
            'statementDate' => $r->statement_date?->toDateString(),
            'statementClosingMinor' => (int) $r->statement_closing_minor,
            'bookBalanceMinor' => (int) $r->book_balance_minor,
            'differenceMinor' => (int) $r->difference_minor,
            'status' => $r->status,
            'preparedBy' => $r->prepared_by,
            'preparedAt' => $r->prepared_at?->toIso8601String(),
            'approvedBy' => $r->approved_by,
            'approvedAt' => $r->approved_at?->toIso8601String(),
        ])->all();

        return ['items' => $items];
    }
}
