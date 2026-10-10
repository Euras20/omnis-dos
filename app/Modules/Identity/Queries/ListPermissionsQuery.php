<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Domain\PermissionCatalogue;

/**
 * GET /api/v1/admin/permissions — catalogue grouped by module.
 */
final class ListPermissionsQuery
{
    /**
     * @return array{modules: list<array{name: string, permissions: list<array<string, mixed>>}>}
     */
    public function execute(): array
    {
        $grouped = PermissionCatalogue::groupedByModule();
        $modules = [];

        foreach ($grouped as $moduleName => $permissions) {
            $modules[] = [
                'name' => $moduleName,
                'permissions' => array_map(static fn (array $p) => [
                    'code' => $p['code'],
                    'area' => $p['area'],
                    'action' => $p['action'],
                    'label' => $p['label'],
                    'sensitive' => $p['sensitive'],
                ], $permissions),
            ];
        }

        return ['modules' => $modules];
    }
}
