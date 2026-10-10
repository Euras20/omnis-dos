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

        $code = strtoupper(trim((string) $data['code']));
        $category = strtolower(trim((string) ($data['category'] ?? '')));
        $kind = strtolower(trim((string) ($data['kind'] ?? 'detail')));
        $normalBalance = strtolower(trim((string) ($data['normalBalance'] ?? 'debit')));

        if (! in_array($category, self::CATEGORIES, true)) {
            throw new BusinessRuleException('category must be asset, liability, equity, income or expense.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new BusinessRuleException('kind must be header or detail.');
        }
        if (! in_array($normalBalance, self::NORMAL_BALANCES, true)) {
            throw new BusinessRuleException('normalBalance must be debit or credit.');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($data, $code, $category, $kind, $normalBalance, $companyId, $actorId) {
            $exists = Account::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->exists();

            if ($exists) {
                throw new ConflictException("An account with code '{$code}' already exists.");
            }

            $account = new Account;
            $account->id = Uuid::v7();
            $account->company_id = $companyId;
            $account->code = $code;
            $account->name = trim((string) $data['name']);
            $account->category = $category;
            $account->kind = $kind;
            $account->currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
            $account->is_active = true;
            $account->fs_line_item = $data['fsLineItem'] ?? $data['fs_line_item'] ?? null;
            $account->fs_sub_line = $data['fsSubLine'] ?? $data['fs_sub_line'] ?? null;
            $account->normal_balance = $normalBalance;
            $account->save();

            $this->auditWriter->write(
                action: 'account.created',
                entityType: 'account',
                entityId: $account->id,
                detail: [
                    'code' => $account->code,
                    'name' => $account->name,
                    'category' => $account->category,
                ],
                actorId: $actorId,
            );

            return $this->toArray($account);
        });
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
            'currency' => $account->currency,
            'isActive' => $account->is_active,
            'fsLineItem' => $account->fs_line_item,
            'fsSubLine' => $account->fs_sub_line,
            'normalBalance' => $account->normal_balance,
            'rowVersion' => $account->row_version,
        ];
    }
}
