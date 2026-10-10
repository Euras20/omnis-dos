<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Models\DocumentTemplate;
use App\Modules\Catalog\Queries\GetDocumentTemplateQuery;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/document-templates/{type}/draft
 */
final class SaveDocumentTemplateDraft
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{settings: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function execute(string $docType, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.templates');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($docType, $data, $companyId, $actorId) {
            $draft = DocumentTemplate::query()
                ->where('company_id', $companyId)
                ->where('doc_type', $docType)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            $published = DocumentTemplate::query()
                ->where('company_id', $companyId)
                ->where('doc_type', $docType)
                ->where('status', 'published')
                ->orderByDesc('version')
                ->first();

            if ($draft === null) {
                $draft = new DocumentTemplate;
                $draft->id = Uuid::v7();
                $draft->company_id = $companyId;
                $draft->doc_type = $docType;
                $draft->status = 'draft';
                $draft->version = ($published?->version ?? 0) + 1;
            }

            $draft->settings = $data['settings'] ?? [];
            $draft->save();

            $this->auditWriter->write(
                action: 'document_template.draft_saved',
                entityType: 'document_template',
                entityId: $draft->id,
                detail: ['docType' => $docType, 'version' => $draft->version],
                actorId: $actorId,
            );

            return GetDocumentTemplateQuery::toArray($draft);
        });
    }
}
