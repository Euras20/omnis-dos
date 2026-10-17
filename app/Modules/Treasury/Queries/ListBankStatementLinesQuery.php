<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Queries;

use App\Models\BankStatementLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/bank/lines — statement lines for reconciliation.
 */
final class ListBankStatementLinesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{bankAccountId?: ?string, status?: ?string, limit?: mixed, offset?: mixed}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function execute(array $filters): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.view');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = BankStatementLine::query()
            ->where('company_id', $companyId);

        if (! empty($filters['bankAccountId'])) {
            $query->where('bank_account_id', (string) $filters['bankAccountId']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('txn_date')->orderByDesc('id')->offset($offset)->limit($limit)->get();

        $items = $rows->map(fn (BankStatementLine $l) => $this->mapLine($l))->all();

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLine(BankStatementLine $l): array
    {
        $signed = $l->direction === 'credit' ? (int) $l->amount_minor : -(int) $l->amount_minor;

        return [
            'id' => $l->id,
            'bankImportId' => $l->bank_import_id,
            'bankAccountId' => $l->bank_account_id,
            'txnDate' => $l->txn_date?->toDateString(),
            'reference' => $l->reference,
            'description' => $l->description,
            'amountMinor' => (int) $l->amount_minor,
            'direction' => $l->direction,
            'signedAmountMinor' => $signed,
            'status' => $l->status,
            'matchedJournalId' => $l->matched_journal_id,
            'matchedAt' => $l->matched_at?->toIso8601String(),
            'excludeReason' => $l->exclude_reason,
        ];
    }
}
