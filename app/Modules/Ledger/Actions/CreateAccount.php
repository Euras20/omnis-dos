<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/accounts — add a chart-of-accounts entry.
 */
final class CreateAccount
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
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.accounts.manage');

        $code = $this->normaliseCode($data['code'] ?? null);
        $category = strtolower(trim((string) ($data['category'] ?? '')));
        $kind = strtolower(trim((string) ($data['kind'] ?? 'detail')));
        $normalBalance = strtolower(trim((string) ($data['normalBalance'] ?? 'debit')));

        if ($category === '' && empty($data['parentId'])) {
            throw new BusinessRuleException('category is required when no parent is given.');
        }
        if ($category !== '' && ! in_array($category, self::CATEGORIES, true)) {
            throw new BusinessRuleException('category must be asset, liability, equity, income or expense.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new BusinessRuleException('kind must be header or detail.');
        }
        if (! in_array($normalBalance, self::NORMAL_BALANCES, true)) {
            throw new BusinessRuleException('normalBalance must be debit or credit.');
        }

        $companyId = $this->companyContext->companyId();
        $parentId = isset($data['parentId']) && $data['parentId'] !== '' ? (string) $data['parentId'] : null;
        $code = $this->normaliseCode($data['code'] ?? null);

        return DB::transaction(function () use ($data, $code, $category, $kind, $normalBalance, $companyId, $actorId, $parentId) {
            $parent = null;
            if ($parentId !== null) {
                $parent = Account::query()
                    ->where('company_id', $companyId)
                    ->where('id', $parentId)
                    ->first();

                if ($parent === null) {
                    throw new BusinessRuleException('Parent account not found.');
                }
                if (($parent->node_type ?? 'POSTING') === 'POSTING') {
                    throw new BusinessRuleException('A posting account cannot contain children.');
                }
                $this->assertParentIsUnderLevelThree($parent, $companyId);

                $category = $parent->category;
                $normalBalance = $parent->normal_balance;
            }

            if ($code !== null) {
                $exists = Account::query()
                    ->where('company_id', $companyId)
                    ->whereRaw('lower(code) = ?', [strtolower($code)])
                    ->exists();

                if ($exists) {
                    throw new ConflictException("An account with code '{$code}' already exists.");
                }
            }

            $nodeType = strtoupper((string) ($data['nodeType'] ?? 'POSTING'));
            if (! in_array($nodeType, ['GROUP', 'POSTING'], true)) {
                throw new BusinessRuleException('nodeType must be GROUP or POSTING.');
            }

            $account = new Account;
            $account->id = Uuid::v7();
            $account->company_id = $companyId;
            $account->parent_id = $parent?->id;
            $account->system_key = null;
            $account->code = $code;
            $account->name = trim((string) $data['name']);
            $account->category = $category;
            $account->kind = $nodeType === 'GROUP' ? 'header' : $kind;
            $account->node_type = $nodeType;
            $account->source = 'CLIENT';
            $account->currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
            $account->is_active = true;
            $account->fs_line_item = $data['fsLineItem'] ?? $data['fs_line_item'] ?? $parent?->report_section;
            $account->fs_sub_line = $data['fsSubLine'] ?? $data['fs_sub_line'] ?? null;
            $account->normal_balance = $normalBalance;
            $account->is_contra = (bool) ($data['isContra'] ?? false);
            $account->statement = $parent?->statement;
            $account->report_section = $parent?->report_section;
            $account->level = $parent !== null ? ((int) $parent->level + 1) : null;
            $account->save();

            $this->auditWriter->write(
                action: 'account.created',
                entityType: 'account',
                entityId: $account->id,
                detail: [
                    'code' => $account->code,
                    'name' => $account->name,
                    'parentId' => $account->parent_id,
                    'nodeType' => $account->node_type,
                ],
                actorId: $actorId,
            );

            return $this->toArray($account);
        });
    }

    private function normaliseCode(mixed $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = trim((string) $code);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertParentIsUnderLevelThree(Account $parent, string $companyId): void
    {
        $current = $parent;
        $guard = 0;
        while ($current !== null && $guard < 12) {
            if ($current->source === 'SYSTEM' && (int) $current->level === 3) {
                return;
            }
            if ($current->source === 'SYSTEM' && (int) $current->level < 3) {
                throw new BusinessRuleException('Add accounts under a level-3 group, not under a class or category.');
            }
            if ($current->parent_id === null) {
                break;
            }
            $current = Account::query()
                ->where('company_id', $companyId)
                ->where('id', $current->parent_id)
                ->first();
            $guard++;
        }

        throw new BusinessRuleException('Parent must sit under a level-3 system group.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Account $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'category' => $account->category,
            'kind' => $account->kind,
            'nodeType' => $account->node_type,
            'source' => $account->source,
            'parentId' => $account->parent_id,
            'systemKey' => $account->system_key,
            'isPostable' => $account->node_type === 'POSTING',
            'currency' => $account->currency,
            'isActive' => $account->is_active,
            'fsLineItem' => $account->fs_line_item,
            'fsSubLine' => $account->fs_sub_line,
            'normalBalance' => $account->normal_balance,
            'statement' => $account->statement,
            'reportSection' => $account->report_section,
            'level' => $account->level,
            'rowVersion' => $account->row_version,
        ];
    }
}
