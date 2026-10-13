<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Company;
use App\Models\DocumentFile;
use App\Models\Party;
use App\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderPdfRenderer;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/v1/documents/purchase-orders/{id}
 * (alias: GET /api/v1/purchase-orders/{id}/pdf)
 *
 * Renders the DOS firm-format purchase order PDF. Always regenerates so
 * earlier blank placeholder files are replaced.
 */
final class GetPurchaseOrderPdfQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PurchaseOrderPdfRenderer $renderer,
    ) {
    }

    /**
     * @return array{bytes: string, fileName: string, documentFileId: string}
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.view');

        $companyId = $this->companyContext->companyId();

        $po = PurchaseOrder::query()
            ->with(['lines', 'supplier'])
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($po === null) {
            throw new NotFoundException('Purchase order not found.');
        }

        $company = Company::query()->where('id', $companyId)->first();
        if ($company === null) {
            throw new NotFoundException('Company not found.');
        }

        /** @var Party|null $supplier */
        $supplier = $po->supplier;
        if ($supplier === null && $po->supplier_id) {
            $supplier = Party::query()
                ->where('company_id', $companyId)
                ->where('id', $po->supplier_id)
                ->first();
        }

        $bytes = $this->renderer->render($po, $company, $supplier);
        $fileName = "PO-{$po->po_no}.pdf";
        $disk = config('omnis.storage.documents_disk', 'local');

        // Replace any previous (including blank placeholder) document_file for this PO.
        $existing = DocumentFile::query()
            ->where('company_id', $companyId)
            ->where('entity_type', 'purchase_order')
            ->where('entity_id', $po->id)
            ->where('mime_type', 'application/pdf')
            ->orderByDesc('uploaded_at')
            ->get();

        foreach ($existing as $old) {
            if ($old->storage_key) {
                Storage::disk($disk)->delete($old->storage_key);
            }
            $old->delete();
        }

        $storageKey = sprintf(
            'companies/%s/documents/purchase_order/%s/%s.pdf',
            $companyId,
            $po->id,
            Uuid::v7()
        );
        Storage::disk($disk)->put($storageKey, $bytes);

        $docId = Uuid::v7();
        DocumentFile::query()->create([
            'id' => $docId,
            'company_id' => $companyId,
            'original_name' => $fileName,
            'storage_key' => $storageKey,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'scan_status' => 'clean',
            'entity_type' => 'purchase_order',
            'entity_id' => $po->id,
            'uploaded_by' => $actorId,
            'uploaded_at' => now(),
        ]);

        return [
            'bytes' => $bytes,
            'fileName' => $fileName,
            'documentFileId' => $docId,
        ];
    }
}
