<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/accounts/group-lookups — main groups and subgroups from the client list.
 */
final class AccountGroupLookupsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array{classes: list<array<string, mixed>>, mainGroups: list<array<string, mixed>>, subgroups: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'ledger.read');

        $path = database_path('data/coa_group_lookups.json');
        $payload = is_file($path)
            ? json_decode((string) file_get_contents($path), true)
            : [];

        $classes = [];
        foreach ($payload['classes'] ?? [] as $c) {
            $classes[] = [
                'code' => $c['code'],
                'class' => $c['class'],
                'systemKey' => $c['system_key'],
            ];
        }

        $main = [];
        foreach ($payload['main_groups'] ?? [] as $g) {
            $main[] = [
                'systemKey' => $g['system_key'],
                'parentSystemKey' => $g['parent'],
                'name' => $g['name'],
                'class' => $g['class'],
            ];
        }

        $sub = [];
        foreach ($payload['subgroups'] ?? [] as $g) {
            $sub[] = [
                'systemKey' => $g['system_key'],
                'parentSystemKey' => $g['parent'],
                'name' => $g['name'],
            ];
        }

        return [
            'classes' => $classes,
            'mainGroups' => $main,
            'subgroups' => $sub,
        ];
    }
}
