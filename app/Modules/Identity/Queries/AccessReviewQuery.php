<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Models\AppUser;
use App\Modules\Identity\Domain\PermissionCatalogue;
use App\Modules\Identity\Services\EffectivePermissionResolver;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/admin/access-review
 *
 * Active users × permissions matrix plus simple SoD conflict hints
 * (pairs of sensitive permissions that should not be held by the same person).
 */
final class AccessReviewQuery
{
    /** Known conflicting pairs (permission A, permission B). */
    private const SOD_PAIRS = [
        ['po.create', 'po.approve'],
        ['ledger.journal.create', 'ledger.journal.post'],
        ['ap.payment.prepare', 'ap.payment.confirm'],
        ['payroll.manage', 'payroll.approve'],
        ['bank.match', 'bank.approve'],
    ];

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly EffectivePermissionResolver $resolver,
    ) {
    }

    /**
     * @return array{sodConflicts: list<array<string, mixed>>, matrix: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $companyId = $this->companyContext->companyId();

        $users = AppUser::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();

        $permissionCodes = PermissionCatalogue::codes();
        $matrix = [];
        $sodConflicts = [];

        foreach ($users as $user) {
            $effective = $this->resolver->forUser($user);
            $allowed = [];
            foreach ($effective as $row) {
                if ($row['allowed']) {
                    $allowed[$row['permission']] = true;
                }
            }

            $matrix[] = [
                'userId' => (string) $user->id,
                'email' => $user->email,
                'displayName' => $user->display_name,
                'permissions' => array_values(array_filter(
                    $permissionCodes,
                    static fn (string $code) => isset($allowed[$code]),
                )),
            ];

            foreach (self::SOD_PAIRS as [$a, $b]) {
                if (isset($allowed[$a], $allowed[$b])) {
                    $sodConflicts[] = [
                        'userId' => (string) $user->id,
                        'displayName' => $user->display_name,
                        'email' => $user->email,
                        'permissionA' => $a,
                        'permissionB' => $b,
                        'message' => "User holds both {$a} and {$b}.",
                    ];
                }
            }
        }

        return [
            'sodConflicts' => $sodConflicts,
            'matrix' => $matrix,
        ];
    }
}
