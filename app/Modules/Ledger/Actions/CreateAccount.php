<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\Journal;
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
        private readonly RoleResolver $roleResolver,
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
        $name = trim((string) $data['name']);

        return DB::transaction(function () use ($data, $code, $category, $kind, $normalBalance, $companyId, $actorId, $parentId, $name) {
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
            } else {
                $code = $this->nextCodeForCategory($category, $companyId);
            }

            $this->assertNameUnique($name, $companyId, null);

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
            $account->name = $name;
            $account->description = isset($data['description']) ? trim((string) $data['description']) : null;
            $account->category = $category;
            $account->detail_type = isset($data['detailType']) ? trim((string) $data['detailType']) : null;
            $account->kind = $nodeType === 'GROUP' ? 'header' : $kind;
            $account->node_type = $nodeType;
            $account->source = 'CLIENT';
            $account->currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
            $account->is_active = true;
            $account->is_locked = (bool) ($data['lockOnCreate'] ?? false);
            if ($account->is_locked) {
                $account->locked_by = $actorId;
                $account->locked_at = now();
                $account->lock_reason = 'Locked when created';
            }
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
                    'detailType' => $account->detail_type,
                    'parentId' => $account->parent_id,
                    'nodeType' => $account->node_type,
                    'isLocked' => $account->is_locked,
                ],
                actorId: $actorId,
            );

            $openingJournalId = $this->maybeCreateOpeningBalance($account, $data, $companyId, $actorId);

            $result = $this->toArray($account);
            if ($openingJournalId !== null) {
                $result['openingBalanceJournalId'] = $openingJournalId;
            }

            return $result;
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

    private function assertNameUnique(string $name, string $companyId, ?string $excludeId): void
    {
        $query = Account::query()
            ->where('company_id', $companyId)
            ->whereRaw('lower(name) = ?', [strtolower($name)]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new ConflictException("An account named '{$name}' already exists. Use the existing account or choose a clearer name.");
        }
    }

    private function nextCodeForCategory(string $category, string $companyId): string
    {
        $ranges = [
            'asset' => [1000, 1999],
            'liability' => [2000, 2999],
            'equity' => [3000, 3999],
            'income' => [4000, 4999],
            'expense' => [5000, 7999],
        ];

        $range = $ranges[$category] ?? [9000, 9999];
        $used = Account::query()
            ->where('company_id', $companyId)
            ->whereNotNull('code')
            ->whereRaw('code ~ ?', ['^[0-9]+$'])
            ->pluck('code')
            ->map(fn ($c) => (int) $c)
            ->filter(fn ($n) => $n >= $range[0] && $n <= $range[1])
            ->all();

        $usedSet = array_flip($used);
        $candidate = empty($used) ? $range[0] : (int) (floor(max($used) / 10) * 10 + 10);

        while ($candidate <= $range[1] && isset($usedSet[$candidate])) {
            $candidate += 10;
        }

        if ($candidate <= $range[1]) {
            return (string) $candidate;
        }

        for ($n = $range[0]; $n <= $range[1]; $n++) {
            if (! isset($usedSet[$n])) {
                return (string) $n;
            }
        }

        throw new BusinessRuleException("No free account number left in the {$category} range.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function maybeCreateOpeningBalance(Account $account, array $data, string $companyId, string $actorId): ?string
    {
        $amount = isset($data['openingBalanceMinor']) ? (int) $data['openingBalanceMinor'] : 0;
        if ($amount <= 0) {
            return null;
        }

        if (! in_array($account->category, ['asset', 'liability', 'equity'], true)) {
            throw new BusinessRuleException('Only balance-sheet accounts take an opening balance.');
        }

        $date = $data['openingBalanceDate'] ?? null;
        if ($date === null || $date === '') {
            throw new BusinessRuleException('openingBalanceDate is required with an opening balance.');
        }

        $clearingId = $this->roleResolver->id($companyId, 'OPENING_BALANCE_EQUITY');

        $isDebit = $account->normal_balance === 'debit';
        $journal = new Journal;
        $journal->id = Uuid::v7();
        $journal->company_id = $companyId;
        $journal->posting_date = $date;
        $journal->description = "Opening balance · {$account->code} {$account->name}";
        $journal->source_type = 'opening';
        $journal->source_id = $account->id;
        $journal->event_type = 'opening_balance';
        $journal->posting_key = 'opening:'.$account->id.':draft';
        $journal->status = 'draft';
        $journal->prepared_by = $actorId;
        $journal->currency = $account->currency ?? 'GHS';
        $journal->save();

        $lineNo = 1;
        if ($isDebit) {
            $this->addJournalLine($journal, $lineNo++, $account->id, $amount, 0, $companyId);
            $this->addJournalLine($journal, $lineNo, $clearingId, 0, $amount, $companyId);
        } else {
            $this->addJournalLine($journal, $lineNo++, $clearingId, $amount, 0, $companyId);
            $this->addJournalLine($journal, $lineNo, $account->id, 0, $amount, $companyId);
        }

        $this->auditWriter->write(
            action: 'account.opening_balance_drafted',
            entityType: 'account',
            entityId: $account->id,
            detail: [
                'journalId' => $journal->id,
                'amountMinor' => $amount,
                'date' => $date,
            ],
            actorId: $actorId,
        );

        return $journal->id;
    }

    private function addJournalLine(Journal $journal, int $lineNo, string $accountId, int $debit, int $credit, string $companyId): void
    {
        $journal->lines()->create([
            'company_id' => $companyId,
            'line_no' => $lineNo,
            'account_id' => $accountId,
            'debit_minor' => $debit,
            'credit_minor' => $credit,
        ]);
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
    }
}
