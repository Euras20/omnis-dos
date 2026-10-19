<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AppUser;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * POST /api/v1/me/signature — store the current user's signature image.
 */
final class UploadSignature
{
    private const MAX_BYTES = 1_048_576; // 1 MB
    private const ALLOWED = ['image/png', 'image/jpeg', 'image/svg+xml'];

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(AppUser $user, UploadedFile $file): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new BusinessRuleException('Signature image must be 1 MB or smaller.');
        }

        $mime = $file->getMimeType() ?? '';
        if (! in_array($mime, self::ALLOWED, true)) {
            throw new BusinessRuleException('Signature must be PNG, JPEG or SVG.');
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/svg+xml' => 'svg',
            default => 'png',
        };

        $path = 'signatures/'.$user->id.'.'.$ext;

        // Remove previous file if the extension changed
        if ($user->signature_path && $user->signature_path !== $path) {
            Storage::disk('local')->delete($user->signature_path);
        }

        Storage::disk('local')->put($path, $file->getContent());

        $user->signature_path = $path;
        $user->save();

        $this->auditWriter->write(
            action: 'signature.uploaded',
            entityType: 'app_user',
            entityId: $user->id,
            detail: ['path' => $path],
            actorId: $this->companyContext->userId(),
        );

        return [
            'hasSignature' => true,
            'uploadedAt' => $user->updated_at?->toIso8601String(),
        ];
    }
}
