<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Party;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/parties — create a customer, supplier, carrier or broker.
 */
final class CreateParty
{
    private const TYPES = ['customer', 'supplier', 'carrier', 'broker'];

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
        $partyType = $data['partyType'] ?? $data['type'] ?? null;

        if (! is_string($partyType) || ! in_array($partyType, self::TYPES, true)) {
            throw new BusinessRuleException('partyType must be customer, supplier, carrier or broker.');
        }

        $this->assertManagePermission($actorId, $partyType);

        $companyId = $this->companyContext->companyId();
        $code = strtoupper(trim((string) $data['code']));

        return DB::transaction(function () use ($data, $partyType, $companyId, $actorId, $code) {
            $exists = Party::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->exists();

            if ($exists) {
                throw new ConflictException("A party with code '{$code}' already exists.");
            }

            $party = new Party;
            $party->id = Uuid::v7();
            $party->company_id = $companyId;
            $party->code = $code;
            $party->name = trim((string) $data['name']);
            $party->party_type = $partyType;
            $party->tin = $data['tin'] ?? null;
            $party->reg_no = $data['regNo'] ?? null;
            $party->city = $data['city'] ?? null;
            $party->region = $data['region'] ?? null;
            $party->country = $data['country'] ?? null;
            $party->address = $data['address'] ?? null;
            $party->gps = $data['gps'] ?? null;
            $party->contact_name = $data['contactName'] ?? $data['contact'] ?? null;
            $party->email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : null;
            $party->phone = $data['phone'] ?? null;
            $party->payment_terms = $data['paymentTerms'] ?? $data['terms'] ?? null;
            $party->credit_limit_minor = isset($data['creditLimitMinor'])
                ? (int) $data['creditLimitMinor']
                : (isset($data['credit']) ? (int) $data['credit'] : null);
            $party->currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
            $party->vat_registered = (bool) ($data['vatRegistered'] ?? $data['vatReg'] ?? false);
            $party->wht_rule_code = $data['whtRuleCode'] ?? $data['wht'] ?? null;
            $party->account_owner = $data['accountOwner'] ?? $data['owner'] ?? null;
            $party->is_main_supplier = (bool) ($data['isMainSupplier'] ?? $data['main'] ?? false);
            $party->contacts = $data['contacts'] ?? null;
            $party->bank_details = $data['bankDetails'] ?? null;
            $party->is_active = true;
            $party->save();

            $this->auditWriter->write(
                action: 'party.created',
                entityType: 'party',
                entityId: $party->id,
                detail: [
                    'code' => $party->code,
                    'name' => $party->name,
                    'partyType' => $party->party_type,
                ],
                actorId: $actorId,
            );

            return $this->toArray($party);
        });
    }

    private function assertManagePermission(string $actorId, string $partyType): void
    {
        $permission = match ($partyType) {
            'customer' => 'customers.manage',
            'supplier' => 'suppliers.manage',
            default => 'suppliers.manage', // carriers / brokers share supplier manage for now
        };

        $this->permissionChecker->assertCan($actorId, $permission);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Party $party): array
    {
        return [
            'id' => (string) $party->id,
            'code' => $party->code,
            'name' => $party->name,
            'partyType' => $party->party_type,
            'tin' => $party->tin,
            'regNo' => $party->reg_no,
            'city' => $party->city,
            'region' => $party->region,
            'country' => $party->country,
            'address' => $party->address,
            'gps' => $party->gps,
            'contactName' => $party->contact_name,
            'email' => $party->email,
            'phone' => $party->phone,
            'paymentTerms' => $party->payment_terms,
            'creditLimitMinor' => $party->credit_limit_minor,
            'currency' => $party->currency,
            'vatRegistered' => (bool) $party->vat_registered,
            'whtRuleCode' => $party->wht_rule_code,
            'accountOwner' => $party->account_owner,
            'isMainSupplier' => (bool) $party->is_main_supplier,
            'contacts' => $party->contacts,
            'bankDetails' => $party->bank_details,
            'isActive' => (bool) $party->is_active,
            'rowVersion' => (int) $party->row_version,
            'createdAt' => $party->created_at?->toIso8601String(),
            'updatedAt' => $party->updated_at?->toIso8601String(),
        ];
    }
}
