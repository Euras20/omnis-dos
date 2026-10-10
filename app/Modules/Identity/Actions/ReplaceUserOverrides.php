<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\AppUser;
use App\Models\UserPermissionOverride;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/admin/users/{id}/overrides — replace individual grants and denies.
 */
final class ReplaceUserOverrides
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{overrides: list<array{permission: string, effect: string, reason?: ?string}>}  $data
     * @return array{userId: string, overrides: list<array<string, mixed>>}
     */
    public function execute(string $userId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.roles');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($userId, $data, $companyId, $actorId) {
            $user = AppUser::query()
                ->where('company_id', $companyId)
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw new NotFoundException('User not found.');
            }

            $overrides = $data['overrides'] ?? [];

            foreach ($overrides as $row) {
                $effect = $row['effect'] ?? '';
                if (! in_array($effect, ['grant', 'deny'], true)) {
                    throw new BusinessRuleException("Override effect must be 'grant' or 'deny'.");
                }
                if (empty($row['permission'])) {
                    throw new BusinessRuleException('Override permission code is required.');
                }
            }

            UserPermissionOverride::query()
                ->where('user_id', $user->id)
                ->delete();

            $saved = [];
            foreach ($overrides as $row) {
                $id = Uuid::v7();
                UserPermissionOverride::query()->create([
                    'id' => $id,
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'permission' => $row['permission'],
                    'effect' => $row['effect'],
                    'reason' => $row['reason'] ?? null,
                    'created_by' => $actorId,
                ]);

                $saved[] = [
                    'id' => $id,
                    'permission' => $row['permission'],
                    'effect' => $row['effect'],
                    'reason' => $row['reason'] ?? null,
                ];
            }

            $this->auditWriter->write(
                action: 'user.overrides_replaced',
                entityType: 'app_user',
                entityId: $user->id,
                detail: [
                    'overrides' => $saved,
                ],
                actorId: $actorId,
            );

            return [
                'userId' => (string) $user->id,
                'overrides' => $saved,
            ];
        });
    }
}
