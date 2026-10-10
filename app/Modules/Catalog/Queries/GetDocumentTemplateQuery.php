<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\DocumentTemplate;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/document-templates/{type}
 */
final class GetDocumentTemplateQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $docType): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'admin.templates');

        $published = DocumentTemplate::query()
            ->where('doc_type', $docType)
            ->where('status', 'published')
            ->first();

        $draft = DocumentTemplate::query()
            ->where('doc_type', $docType)
            ->where('status', 'draft')
            ->first();

        $history = DocumentTemplate::query()
            ->where('doc_type', $docType)
            ->where('status', 'published')
            ->orderByDesc('version')
            ->get()
            ->map(fn (DocumentTemplate $t) => [
                'id' => (string) $t->id,
                'version' => $t->version,
                'publishedAt' => $t->published_at?->toIso8601String(),
                'publishedBy' => $t->published_by,
            ])
            ->all();

        return [
            'docType' => $docType,
            'published' => $published ? self::toArray($published) : null,
            'draft' => $draft ? self::toArray($draft) : null,
            'history' => $history,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(DocumentTemplate $t): array
    {
        return [
            'id' => (string) $t->id,
            'docType' => $t->doc_type,
            'status' => $t->status,
            'version' => $t->version,
            'settings' => $t->settings,
            'publishedBy' => $t->published_by,
            'publishedAt' => $t->published_at?->toIso8601String(),
            'rowVersion' => (int) $t->row_version,
        ];
    }
}
