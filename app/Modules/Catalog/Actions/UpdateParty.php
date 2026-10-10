<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\NotFoundException;
use App\Models\Party;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Concurrency\RowVersion;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/v1/parties/{id} — edit party masters (optimistic concurrency).
 */
final class UpdateParty
{
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
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $data, $actorId, $companyId) {
            $party = Party::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($party === null) {
                throw NotFoundException::resource('Party', $id);
            }

            $this->assertManagePermission($actorId, $party->party_type);

            RowVersion::assertMatches($party, $data['rowVersion'] ?? null);

            $mutable = [
                'name' => 'name',
                'tin' => 'tin',
                'regNo' => 'reg_no',
                'city' => 'city',
                'region' => 'region',
                'country' => 'country',
                'address' => 'address',
                'gps' => 'gps',
                'contactName' => 'contact_name',
                'email' => 'email',
                'phone' => 'phone',
                'paymentTerms' => 'payment_terms',
                'creditLimitMinor' => 'credit_limit_minor',
                'currency' => 'currency',
                'vatRegistered' => 'vat_registered',
                'whtRuleCode' => 'wht_rule_code',
                'accountOwner' => 'account_owner',
                'isMainSupplier' => 'is_main_supplier',
                'contacts' => 'contacts',
                'bankDetails' => 'bank_details',
                'isActive' => 'is_active',
            ];

            foreach ($mutable as $input => $column) {
                if (! array_key_exists($input, $data)) {
                    continue;
                }

                $value = $data[$input];
                if ($input === 'email' && is_string($value)) {
                    $value = strtolower(trim($value));
                }
                if ($input === 'currency' && is_string($value)) {
                    $value = strtoupper($value);
                }
                if ($input === 'name' && is_string($value)) {
                    $value = trim($value);
                }

                $party->setAttribute($column, $value);
            }

            RowVersion::bump($party);
            $party->save();

            $this->auditWriter->write(
                action: 'party.updated',
                entityType: 'party',
                entityId: $party->id,
                detail: [
                    'code' => $party->code,
                    'name' => $party->name,
                    'isActive' => $party->is_active,
                ],
                actorId: $actorId,
            );

            return CreateParty::toArray($party);
        });
    }

    private function assertManagePermission(string $actorId, string $partyType): void
    {
        $permission = match ($partyType) {
            'customer' => 'customers.manage',
            default => 'suppliers.manage',
        };

        $this->permissionChecker->assertCan($actorId, $permission);
    }
}
