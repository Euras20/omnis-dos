<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\NotFoundException;
use App\Models\DocumentTemplate;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /api/v1/document-templates/{type}/draft
 */
final class DiscardDocumentTemplateDraft
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array{docType: string, discarded: bool}
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

            $id = $draft->id;
            $draft->delete();

            $this->auditWriter->write(
                action: 'document_template.draft_discarded',
                entityType: 'document_template',
                entityId: $id,
                detail: ['docType' => $docType],
                actorId: $actorId,
            );

            return ['docType' => $docType, 'discarded' => true];
        });
    }
}
