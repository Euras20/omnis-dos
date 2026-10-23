<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/quotations — sent vs accepted, overall and by customer.
 */
final class QuotationConversionQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{from?: ?string, to?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = []): array
    {
        $actor = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actor, 'sales.quotes.view')
            && ! $this->permissionChecker->can($actor, 'reports.financial')) {
            $this->permissionChecker->assertCan($actor, 'sales.quotes.view');
        }

        $companyId = $this->companyContext->companyId();
        $from = isset($filters['from']) && is_string($filters['from']) && $filters['from'] !== '' ? $filters['from'] : null;
        $to = isset($filters['to']) && is_string($filters['to']) && $filters['to'] !== '' ? $filters['to'] : null;

        $base = DB::table('quotation')->where('quotation.company_id', $companyId);
        if ($from !== null) {
            $base->where('quotation.quotation_date', '>=', $from);
        }
        if ($to !== null) {
            $base->where('quotation.quotation_date', '<=', $to);
        }

        $counts = (clone $base)
            ->selectRaw('status, (COUNT(*))::int AS n, COALESCE(SUM(gross_minor), 0)::bigint AS gross')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $sent = (int) ($counts['sent']->n ?? 0)
            + (int) ($counts['accepted']->n ?? 0)
            + (int) ($counts['converted']->n ?? 0)
            + (int) ($counts['declined']->n ?? 0)
            + (int) ($counts['expired']->n ?? 0);
        $accepted = (int) ($counts['accepted']->n ?? 0) + (int) ($counts['converted']->n ?? 0);

        $byCustomer = (clone $base)
            ->join('party', 'party.id', '=', 'quotation.customer_id')
            ->selectRaw("party.id AS customer_id, party.name AS customer_name,
                (COUNT(*) FILTER (WHERE quotation.status <> 'draft'))::int AS sent,
                (COUNT(*) FILTER (WHERE quotation.status IN ('accepted', 'converted')))::int AS accepted,
                COALESCE(SUM(quotation.gross_minor) FILTER (WHERE quotation.status <> 'draft'), 0)::bigint AS sent_gross,
                COALESCE(SUM(quotation.gross_minor) FILTER (WHERE quotation.status IN ('accepted', 'converted')), 0)::bigint AS accepted_gross")
            ->groupBy('party.id', 'party.name')
            ->orderByDesc('sent')
            ->get()
            ->map(static function ($row) {
                $sent = (int) $row->sent;
                $accepted = (int) $row->accepted;

                return [
                    'customerId' => (string) $row->customer_id,
                    'customerName' => (string) $row->customer_name,
                    'sent' => $sent,
                    'accepted' => $accepted,
                    'acceptanceRate' => $sent > 0 ? round($accepted / $sent * 100, 1) : null,
                    'sentGrossMinor' => (int) $row->sent_gross,
                    'acceptedGrossMinor' => (int) $row->accepted_gross,
                ];
            })
            ->all();

        return [
            'from' => $from,
            'to' => $to,
            'overall' => [
                'sent' => $sent,
                'accepted' => $accepted,
                'declined' => (int) ($counts['declined']->n ?? 0),
                'expired' => (int) ($counts['expired']->n ?? 0),
                'draft' => (int) ($counts['draft']->n ?? 0),
                'acceptanceRate' => $sent > 0 ? round($accepted / $sent * 100, 1) : null,
            ],
            'byCustomer' => $byCustomer,
        ];
    }
}
