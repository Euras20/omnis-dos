<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Handlers;

use App\Models\DocumentFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders a PDF for a posted document and stores metadata in document_file.
 *
 * Idempotent: if a document_file already exists for (company, entity_type, entity_id)
 * with matching storage key convention, this is a no-op.
 *
 * Real PDF rendering (Dompdf / Browsershot / external service) is left as a
 * pluggable step; this handler records the storage key and metadata so the
 * API can return a download reference. Binary generation can be swapped without
 * changing the outbox contract.
 *
 * event_type: document.pdf_requested
 * payload keys: companyId, entityType, entityId, docType, originalName (optional)
 */
final class PdfGenerateHandler
{
    public function __invoke(array $payload): void
    {
        $companyId = (string) ($payload['companyId'] ?? $payload['company_id'] ?? '');
        $entityType = (string) ($payload['entityType'] ?? $payload['entity_type'] ?? '');
        $entityId = (string) ($payload['entityId'] ?? $payload['entity_id'] ?? '');
        $docType = (string) ($payload['docType'] ?? $payload['doc_type'] ?? $entityType);
        $originalName = (string) ($payload['originalName'] ?? $payload['original_name'] ?? "{$docType}-{$entityId}.pdf");

        if ($companyId === '' || $entityType === '' || $entityId === '') {
            throw new \InvalidArgumentException(
                'PdfGenerateHandler requires companyId, entityType and entityId in payload.'
            );
        }

        // Idempotency: already materialised?
        $existing = DocumentFile::query()
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('mime_type', 'application/pdf')
            ->first();

        if ($existing !== null) {
            Log::info('PdfGenerateHandler: document already exists, skipping', [
                'document_file_id' => $existing->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return;
        }

        $storageKey = sprintf(
            'companies/%s/documents/%s/%s/%s.pdf',
            $companyId,
            $entityType,
            $entityId,
            Str::uuid()->toString()
        );

        // Placeholder content — replace with real renderer when templates are wired.
        $pdfBytes = $this->renderPlaceholderPdf($docType, $entityId, $payload);

        Storage::disk(config('omnis.storage.documents_disk', 'local'))
            ->put($storageKey, $pdfBytes);

        DocumentFile::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'original_name' => $originalName,
            'storage_key' => $storageKey,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdfBytes),
            'scan_status' => 'clean',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'uploaded_by' => $payload['requestedBy'] ?? $payload['requested_by'] ?? null,
            'uploaded_at' => now(),
        ]);

        Log::info('PdfGenerateHandler: document stored', [
            'storage_key' => $storageKey,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'bytes' => strlen($pdfBytes),
        ]);
    }

    /**
     * Minimal stand-in PDF so the pipeline is testable without a full renderer.
     * Production will inject a real DocumentRenderer service.
     */
    private function renderPlaceholderPdf(string $docType, string $entityId, array $payload): string
    {
        $title = strtoupper($docType).' '.$entityId;
        $body = "OMNIS document\n{$title}\nGenerated at ".now()->toIso8601String();

        // Valid minimal PDF structure (enough for storage + size checks).
        $stream = "<< /Length ".strlen($body)." >>\nstream\n{$body}\nendstream";
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
        $pdf .= "xref\n0 ".(count($offsets))."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer<< /Size ".count($offsets)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }
}
