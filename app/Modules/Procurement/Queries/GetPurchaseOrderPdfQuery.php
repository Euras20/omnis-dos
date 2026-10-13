<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Exceptions\NotFoundException;
use App\Models\DocumentFile;
use App\Models\PurchaseOrder;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/v1/documents/purchase-orders/{id}
 * (alias: GET /api/v1/purchase-orders/{id}/pdf)
 *
 * Returns PDF bytes for a PO. Uses an existing document_file when present;
 * otherwise generates a placeholder PDF (same contract as PdfGenerateHandler)
 * and stores metadata so subsequent requests are stable.
 */
final class GetPurchaseOrderPdfQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
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
            ->with('lines')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($po === null) {
            throw new NotFoundException('Purchase order not found.');
        }

        $existing = DocumentFile::query()
            ->where('company_id', $companyId)
            ->where('entity_type', 'purchase_order')
            ->where('entity_id', $po->id)
            ->where('mime_type', 'application/pdf')
            ->orderByDesc('uploaded_at')
            ->first();

        $disk = config('omnis.storage.documents_disk', 'local');

        if ($existing !== null && Storage::disk($disk)->exists($existing->storage_key)) {
            $bytes = Storage::disk($disk)->get($existing->storage_key);

            return [
                'bytes' => $bytes,
                'fileName' => $existing->original_name ?: "PO-{$po->po_no}.pdf",
                'documentFileId' => $existing->id,
            ];
        }

        $bytes = $this->renderPdf($po);
        $fileName = "PO-{$po->po_no}.pdf";
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

    private function renderPdf(PurchaseOrder $po): string
    {
        $lines = [];
        $lines[] = "OMNIS Purchase Order {$po->po_no}";
        $lines[] = "Status: {$po->status}";
        $lines[] = 'Order date: '.($po->order_date?->format('Y-m-d') ?? '');
        $lines[] = "Supplier: {$po->supplier_id}";
        $lines[] = "Currency: {$po->currency}";
        $lines[] = 'Gross (minor): '.(string) $po->gross_minor;
        $lines[] = '';
        $lines[] = 'Lines:';
        foreach ($po->lines as $line) {
            $lines[] = sprintf(
                '  %d. %s qty=%s unit=%s unitPriceMinor=%d received=%s',
                $line->line_no,
                $line->description,
                (string) $line->qty,
                $line->unit,
                $line->unit_price_minor,
                (string) $line->qty_received
            );
        }
        $lines[] = '';
        $lines[] = 'Generated at '.now()->toIso8601String();
        $body = implode("\n", $lines);

        // Minimal valid PDF (placeholder until DocumentTemplate rendering is wired).
        $stream = '<< /Length '.strlen($body)." >>\nstream\n{$body}\nendstream";
        $objects = [
            '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj',
            '2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj',
            '3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>endobj',
            "4 0 obj{$stream}endobj",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj."\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= 'xref\n0 '.(count($offsets))."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer<< /Size '.count($offsets)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }
}
