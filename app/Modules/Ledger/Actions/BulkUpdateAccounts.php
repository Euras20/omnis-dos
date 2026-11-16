<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/accounts/bulk — update several accounts in one transaction.
 */
final class BulkUpdateAccounts
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{items: list<array<string, mixed>>}  $data
     * @return array{updated: int}
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.accounts.manage');

        $items = $data['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new BusinessRuleException('items is required.');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($items, $companyId, $actorId) {
            $updated = 0;

            foreach ($items as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }

                $account = Account::query()
                    ->where('company_id', $companyId)
                    ->where('id', $row['id'])
                    ->lockForUpdate()
                    ->first();

                if ($account === null || $account->source === 'SYSTEM') {
                    continue;
                }

                $changed = false;

                if (isset($row['name'])) {
                    $name = trim((string) $row['name']);
                    if ($name !== '' && $name !== $account->name) {
                        $account->name = $name;
                        $changed = true;
                    }
                }
                if (array_key_exists('description', $row)) {
                    $account->description = $row['description'] !== null ? trim((string) $row['description']) : null;
                    $changed = true;
                }
                if (array_key_exists('detailType', $row)) {
                    $account->detail_type = $row['detailType'] !== null ? trim((string) $row['detailType']) : null;
                    $changed = true;
                }
                if (isset($row['isActive'])) {
                    $account->is_active = (bool) $row['isActive'];
                    $changed = true;
                }

                if ($changed) {
                    $account->row_version = (int) $account->row_version + 1;
                    $account->save();
                    $updated++;

                    $this->auditWriter->write(
                        action: 'account.bulk_updated',
                        entityType: 'account',
                        entityId: $account->id,
                        detail: ['code' => $account->code],
                        actorId: $actorId,
                    );
                }
            }

            $this->auditWriter->write(
                action: 'account.bulk_edit',
                entityType: 'account',
                entityId: $companyId,
                detail: ['updated' => $updated],
                actorId: $actorId,
            );

            return ['updated' => $updated];
        });
    }
}
