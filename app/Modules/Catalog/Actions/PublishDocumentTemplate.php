<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\DocumentTemplate;
use App\Modules\Catalog\Queries\GetDocumentTemplateQuery;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/document-templates/{type}/publish
 *
 * Promotes draft to published. Previous published row is kept as history
 * by changing status semantics: we keep published unique via partial index
 * by first demoting the old published to archived status, then promoting draft.
 */
final class PublishDocumentTemplate
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $docType): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.templates');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($docType, $companyId, $actorId) {
            $draft = DocumentTemplate::query()
                ->where('company_id', $companyId)
                ->where('doc_type', $docType)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw NotFoundException::resource('Document template draft', $docType);
            }

            if ($draft->settings === null || $draft->settings === []) {
                throw new BusinessRuleException('Draft settings are empty; cannot publish.');
            }

            // Demote current published (if any) to archived so partial unique allows the new published.
            DocumentTemplate::query()
                ->where('company_id', $companyId)
                ->where('doc_type', $docType)
                ->where('status', 'published')
                ->update(['status' => 'archived']);

            $draft->status = 'published';
            $draft->published_by = $actorId;
            $draft->published_at = now();
            $draft->save();

            $this->auditWriter->write(
                action: 'document_template.published',
                entityType: 'document_template',
                entityId: $draft->id,
                detail: ['docType' => $docType, 'version' => $draft->version],
                actorId: $actorId,
            );

            return GetDocumentTemplateQuery::toArray($draft);
        });
    }
}
