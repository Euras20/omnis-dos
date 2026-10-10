<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\SaveTemplateDraftRequest;
use App\Modules\Catalog\Actions\DiscardDocumentTemplateDraft;
use App\Modules\Catalog\Actions\PublishDocumentTemplate;
use App\Modules\Catalog\Actions\SaveDocumentTemplateDraft;
use App\Modules\Catalog\Queries\GetDocumentTemplateQuery;
use App\Modules\Catalog\Queries\ListDocumentTemplatesQuery;
use Illuminate\Http\JsonResponse;

final class DocumentTemplateController
{
    public function __construct(
        private readonly ListDocumentTemplatesQuery $listDocumentTemplatesQuery,
        private readonly GetDocumentTemplateQuery $getDocumentTemplateQuery,
        private readonly SaveDocumentTemplateDraft $saveDocumentTemplateDraft,
        private readonly PublishDocumentTemplate $publishDocumentTemplate,
        private readonly DiscardDocumentTemplateDraft $discardDocumentTemplateDraft,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->listDocumentTemplatesQuery->execute());
    }

    public function show(string $type): JsonResponse
    {
        return response()->json($this->getDocumentTemplateQuery->execute($type));
    }

    public function saveDraft(string $type, SaveTemplateDraftRequest $request): JsonResponse
    {
        $draft = $this->saveDocumentTemplateDraft->execute($type, $request->validated());

        return response()->json($draft);
    }

    public function discardDraft(string $type): JsonResponse
    {
        return response()->json($this->discardDocumentTemplateDraft->execute($type));
    }

    public function publish(string $type): JsonResponse
    {
        return response()->json($this->publishDocumentTemplate->execute($type));
    }
}
