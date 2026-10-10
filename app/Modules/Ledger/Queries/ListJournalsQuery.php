<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Models\Journal;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/journals — list journals (posted by default).
 */
final class ListJournalsQuery
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

        $query = Journal::query()
            ->where('company_id', $companyId)
            ->orderByDesc('posting_date')
            ->orderByDesc('journal_no');

        $status = $filters['status'] ?? 'posted';
        if (is_string($status) && $status !== 'all') {
            $query->where('status', $status);
        }

        if (isset($filters['from']) && is_string($filters['from'])) {
            $query->where('posting_date', '>=', $filters['from']);
        }
        if (isset($filters['to']) && is_string($filters['to'])) {
            $query->where('posting_date', '<=', $filters['to']);
        }
        if (isset($filters['sourceType']) && is_string($filters['sourceType'])) {
            $query->where('source_type', $filters['sourceType']);
        }
        if (isset($filters['q']) && is_string($filters['q']) && $filters['q'] !== '') {
            $q = '%'.$filters['q'].'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('journal_no', 'ilike', $q)
                    ->orWhere('description', 'ilike', $q)
                    ->orWhere('posting_key', 'ilike', $q);
            });
        }

        $total = (clone $query)->count();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $journals = $query->limit($limit)->offset($offset)->get();

        $items = [];
        foreach ($journals as $journal) {
            $items[] = [
                'id' => $journal->id,
                'journalNo' => $journal->journal_no,
                'postingDate' => $journal->posting_date?->format('Y-m-d'),
                'description' => $journal->description,
                'sourceType' => $journal->source_type,
                'sourceId' => $journal->source_id,
                'status' => $journal->status,
                'currency' => $journal->currency,
                'preparedBy' => $journal->prepared_by,
                'postedBy' => $journal->posted_by,
                'postedAt' => $journal->posted_at?->toIso8601String(),
                'reversesJournalId' => $journal->reverses_journal_id,
                'reversedByJournalId' => $journal->reversed_by_journal_id,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
