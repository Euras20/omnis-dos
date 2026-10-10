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

            if (isset($data['name'])) {
                $account->name = trim((string) $data['name']);
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
                'category' => $account->category,
                'kind' => $account->kind,
                'currency' => $account->currency,
                'isActive' => $account->is_active,
                'fsLineItem' => $account->fs_line_item,
                'fsSubLine' => $account->fs_sub_line,
                'normalBalance' => $account->normal_balance,
                'rowVersion' => $account->row_version,
            ];
        });
    }
}
