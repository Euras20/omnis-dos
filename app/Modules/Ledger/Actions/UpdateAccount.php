<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Exceptions\StaleRevisionException;
use App\Models\Account;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/v1/accounts/{id} — edit or deactivate an account.
 */
final class UpdateAccount
{
    private const CATEGORIES = ['asset', 'liability', 'equity', 'income', 'expense'];

    private const KINDS = ['header', 'detail'];

    private const NORMAL_BALANCES = ['debit', 'credit'];

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

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $data, $companyId, $actorId) {
            $account = Account::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw new NotFoundException('Account not found.');
            }

            $expectedVersion = isset($data['rowVersion']) ? (int) $data['rowVersion'] : null;
            if ($expectedVersion !== null && $expectedVersion !== (int) $account->row_version) {
                throw new StaleRevisionException('Account was modified by another user. Reload and retry.');
            }

            if ($account->source === 'SYSTEM') {
                $this->applySystemGroupUpdate($account, $data);
            } else {
                $this->applyClientUpdate($account, $data);
            }

            $account->row_version = (int) $account->row_version + 1;
            $account->save();

            $this->auditWriter->write(
                action: 'account.updated',
                entityType: 'account',
                entityId: $account->id,
                detail: [
                    'code' => $account->code,
                    'isActive' => $account->is_active,
                    'rowVersion' => $account->row_version,
                ],
                actorId: $actorId,
            );

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'description' => $account->description,
                'category' => $account->category,
                'detailType' => $account->detail_type,
                'kind' => $account->kind,
                'nodeType' => $account->node_type,
                'source' => $account->source,
                'parentId' => $account->parent_id,
                'systemKey' => $account->system_key,
                'isPostable' => $account->node_type === 'POSTING',
                'currency' => $account->currency,
                'isActive' => $account->is_active,
                'isLocked' => $account->is_locked,
                'lockedBy' => $account->locked_by,
                'lockedAt' => $account->locked_at?->toIso8601String(),
                'lockReason' => $account->lock_reason,
                'fsLineItem' => $account->fs_line_item,
                'fsSubLine' => $account->fs_sub_line,
                'normalBalance' => $account->normal_balance,
                'statement' => $account->statement,
                'reportSection' => $account->report_section,
                'level' => $account->level,
                'rowVersion' => $account->row_version,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applySystemGroupUpdate(Account $account, array $data): void
    {
        if (isset($data['name'])) {
            $account->name = trim((string) $data['name']);
        }
        if (array_key_exists('code', $data)) {
            $account->code = $this->normaliseCode($data['code'], $account);
        }
        if (isset($data['isActive'])) {
            $account->is_active = (bool) $data['isActive'];
        }
        if (array_key_exists('reportSection', $data) || array_key_exists('report_section', $data)) {
            $account->report_section = $data['reportSection'] ?? $data['report_section'] ?? null;
            $account->fs_line_item = $account->report_section;
        }
        if (isset($data['normalBalance'])) {
            $nb = strtolower(trim((string) $data['normalBalance']));
            if (! in_array($nb, self::NORMAL_BALANCES, true)) {
                throw new BusinessRuleException('normalBalance must be debit or credit.');
            }
            $account->normal_balance = $nb;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyClientUpdate(Account $account, array $data): void
    {
        if (isset($data['name'])) {
            $newName = trim((string) $data['name']);
            if ($newName !== $account->name) {
                $this->assertNameUnique($newName, $account->company_id, $account->id);
            }
            $account->name = $newName;
        }
        if (array_key_exists('description', $data)) {
            $account->description = $data['description'] !== null ? trim((string) $data['description']) : null;
        }
        if (array_key_exists('detailType', $data)) {
            $account->detail_type = $data['detailType'] !== null ? trim((string) $data['detailType']) : null;
        }
        if (array_key_exists('code', $data)) {
            $account->code = $this->normaliseCode($data['code'], $account);
        }
        if (isset($data['category'])) {
            $category = strtolower(trim((string) $data['category']));
            if (! in_array($category, self::CATEGORIES, true)) {
                throw new BusinessRuleException('category must be asset, liability, equity, income or expense.');
            }
            $account->category = $category;
        }
        if (isset($data['kind'])) {
            $kind = strtolower(trim((string) $data['kind']));
            if (! in_array($kind, self::KINDS, true)) {
                throw new BusinessRuleException('kind must be header or detail.');
            }
            $account->kind = $kind;
        }
        if (array_key_exists('currency', $data)) {
            $account->currency = $data['currency'] !== null
                ? strtoupper((string) $data['currency'])
                : null;
        }
        if (isset($data['isActive'])) {
            $account->is_active = (bool) $data['isActive'];
        }
        if (array_key_exists('fsLineItem', $data) || array_key_exists('fs_line_item', $data)) {
            $account->fs_line_item = $data['fsLineItem'] ?? $data['fs_line_item'] ?? null;
        }
        if (array_key_exists('fsSubLine', $data) || array_key_exists('fs_sub_line', $data)) {
            $account->fs_sub_line = $data['fsSubLine'] ?? $data['fs_sub_line'] ?? null;
        }
        if (isset($data['normalBalance'])) {
            $nb = strtolower(trim((string) $data['normalBalance']));
            if (! in_array($nb, self::NORMAL_BALANCES, true)) {
                throw new BusinessRuleException('normalBalance must be debit or credit.');
            }
            $account->normal_balance = $nb;
        }
    }

    private function assertNameUnique(string $name, string $companyId, ?string $excludeId): void
    {
        $query = Account::query()
            ->where('company_id', $companyId)
            ->whereRaw('lower(name) = ?', [strtolower($name)]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new BusinessRuleException("An account named '{$name}' already exists. Use the existing account or choose a clearer name.");
        }
    }

    private function normaliseCode(mixed $code, Account $account): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = trim((string) $code);
        if ($trimmed === '') {
            return null;
        }

        $taken = Account::query()
            ->where('company_id', $account->company_id)
            ->where('id', '!=', $account->id)
            ->whereRaw('lower(code) = ?', [strtolower($trimmed)])
            ->exists();

        if ($taken) {
            throw new BusinessRuleException("An account with code '{$trimmed}' already exists.");
        }

        return $trimmed;
    }
}
