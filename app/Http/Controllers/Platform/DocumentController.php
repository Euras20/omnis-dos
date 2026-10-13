<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Modules\Procurement\Queries\GetPurchaseOrderPdfQuery;
use Illuminate\Http\Response;

/**
 * Document downloads under /api/v1/documents/...
 */
final class DocumentController
{
    public function __construct(
        private readonly GetPurchaseOrderPdfQuery $getPurchaseOrderPdfQuery,
    ) {
    }

    public function purchaseOrder(string $id): Response
    {
        $result = $this->getPurchaseOrderPdfQuery->execute($id);

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$result['fileName'].'"',
            'X-Document-File-Id' => $result['documentFileId'],
        ]);
    }
}
