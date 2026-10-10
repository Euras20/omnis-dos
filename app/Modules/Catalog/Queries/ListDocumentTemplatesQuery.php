<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\DocumentTemplate;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/document-templates — types with published version and draft flag.
 */
final class ListDocumentTemplatesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'admin.templates');

        $rows = DocumentTemplate::query()
            ->orderBy('doc_type')
            ->orderByDesc('version')
            ->get()
            ->groupBy('doc_type');

        $items = [];
        foreach ($rows as $docType => $versions) {
            $published = $versions->firstWhere('status', 'published');
            $draft = $versions->firstWhere('status', 'draft');

            $items[] = [
                'docType' => $docType,
                'publishedVersion' => $published?->version,
                'publishedAt' => $published?->published_at?->toIso8601String(),
                'hasDraft' => $draft !== null,
                'draftVersion' => $draft?->version,
            ];
        }

        return ['items' => $items];
    }
}
