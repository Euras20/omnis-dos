<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Queries;

use App\Models\CustomsDeclaration;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/** GET /api/v1/customs — list declarations. */
final class ListCustomsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.view');

        $companyId = $this->companyContext->companyId();

        $query = CustomsDeclaration::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static fn (CustomsDeclaration $d) => [
                'id' => $d->id,
                'declarationNo' => $d->declaration_no,
                'shipmentId' => $d->shipment_id,
                'consignmentId' => $d->consignment_id,
                'jurisdiction' => $d->jurisdiction,
                'brokerId' => $d->broker_id,
                'hsCode' => $d->hs_code,
                'dutiesMinor' => $d->duties_minor,
                'recoverableTaxMinor' => $d->recoverable_tax_minor,
                'nonrecoverableMinor' => $d->nonrecoverable_minor,
                'status' => $d->status,
                'freeTimeEnd' => $d->free_time_end?->format('Y-m-d'),
                'demurrageMinor' => $d->demurrage_minor,
                'missingDocs' => self::missingDocs($d->docs),
                'createdAt' => $d->created_at?->toIso8601String(),
            ])->all(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $docs
     * @return list<string>
     */
    private static function missingDocs(?array $docs): array
    {
        if ($docs === null) {
            return [];
        }

        $missing = [];
        foreach ($docs as $name => $present) {
            if (! $present) {
                $missing[] = (string) $name;
            }
        }

        return $missing;
    }
}
