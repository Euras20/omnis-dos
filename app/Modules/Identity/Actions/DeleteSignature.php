<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\NotFoundException;
use App\Models\AppUser;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\Storage;

/**
 * DELETE /api/v1/me/signature — remove the current user's signature.
 */
final class DeleteSignature
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    public function execute(AppUser $user): void
    {
        if (! $user->signature_path) {
            throw new NotFoundException('No signature on file.');
        }

        Storage::disk('local')->delete($user->signature_path);
        $user->signature_path = null;
        $user->save();

        $this->auditWriter->write(
            action: 'signature.deleted',
            entityType: 'app_user',
            entityId: $user->id,
            detail: [],
            actorId: $this->companyContext->userId(),
        );
    }
}
