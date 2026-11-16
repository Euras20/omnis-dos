<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/accounts/{id}/lock — lock an account (no new postings).
 */
final class LockAccount
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.accounts.manage');

        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) {
            throw new BusinessRuleException('A reason of at least 5 characters is required.');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $reason, $companyId, $actorId) {
            $account = Account::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw new NotFoundException('Account not found.');
            }

            if ($account->is_locked) {
                throw new BusinessRuleException('Account is already locked.');
            }

            $account->is_locked = true;
            $account->locked_by = $actorId;
            $account->locked_at = now();
            $account->lock_reason = $reason;
            $account->row_version = (int) $account->row_version + 1;
            $account->save();

            $this->auditWriter->write(
                action: 'account.locked',
                entityType: 'account',
                entityId: $account->id,
                detail: ['reason' => $reason],
                actorId: $actorId,
            );

            return [
                'id' => $account->id,
                'isLocked' => true,
                'lockedBy' => $account->locked_by,
                'lockedAt' => $account->locked_at?->toIso8601String(),
                'lockReason' => $account->lock_reason,
                'rowVersion' => $account->row_version,
            ];
        });
    }
}
