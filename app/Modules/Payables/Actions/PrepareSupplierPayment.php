<?php

declare(strict_types=1);

namespace App\Modules\Payables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Bill;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\PaymentAllocation;
use App\Models\SupplierPayment;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/supplier-payments — prepare a payment (no journal yet).
 */
final class PrepareSupplierPayment
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
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ap.payment.prepare');

        $companyId = $this->companyContext->companyId();
        $supplierId = (string) ($data['supplierId'] ?? '');
        $rawAllocations = $data['allocations'] ?? [];
        $whtMinor = (int) ($data['whtMinor'] ?? 0);
        $cashAccountCode = (string) ($data['cashAccount'] ?? $data['cashAccountCode'] ?? '1000');
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($supplierId === '') {
            throw new BusinessRuleException('supplierId is required.');
        }
        if (! is_array($rawAllocations) || $rawAllocations === []) {
            throw new BusinessRuleException('At least one allocation is required.');
        }
        if ($whtMinor < 0) {
            throw new BusinessRuleException('whtMinor cannot be negative.');
        }

        $supplier = Party::query()
            ->where('company_id', $companyId)
            ->where('id', $supplierId)
            ->where('party_type', 'supplier')
            ->where('is_active', true)
            ->first();
        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        $allocSum = 0;
        $resolved = [];
        foreach ($rawAllocations as $i => $raw) {
            if (! is_array($raw)) {
                throw new BusinessRuleException("Allocation {$i} is invalid.");
            }
            $billId = (string) ($raw['billId'] ?? '');
            $amount = (int) ($raw['amountMinor'] ?? 0);
            if ($billId === '' || $amount <= 0) {
                throw new BusinessRuleException("Allocation {$i} requires billId and positive amountMinor.");
            }
            $bill = Bill::query()
                ->where('company_id', $companyId)
                ->where('id', $billId)
                ->where('supplier_id', $supplierId)
                ->where('status', 'posted')
                ->first();
            if ($bill === null) {
                throw new NotFoundException("Bill {$billId} not found for this supplier.");
            }
            $open = (int) DB::selectOne('SELECT bill_open(?::uuid, CURRENT_DATE) AS open', [$billId])->open;
            // Also subtract amounts already reserved in prepared payments
            $reserved = (int) DB::table('payment_allocation as pa')
                ->join('supplier_payment as sp', 'sp.id', '=', 'pa.supplier_payment_id')
                ->where('pa.bill_id', $billId)
                ->where('sp.status', 'prepared')
                ->sum('pa.amount_minor');
            $available = $open - $reserved;
            if ($amount > $available) {
                throw new BusinessRuleException("Allocation to bill {$bill->bill_no} exceeds available ({$available}).");
            }
            $resolved[] = ['billId' => $billId, 'amountMinor' => $amount];
            $allocSum += $amount;
        }

        if ($whtMinor > $allocSum) {
            throw new BusinessRuleException('whtMinor cannot exceed gross allocation.');
        }

        $netMinor = $allocSum - $whtMinor;
        $currency = strtoupper((string) ($data['currency'] ?? $supplier->currency ?? 'GHS'));

        return DB::transaction(function () use (
            $companyId,
            $supplierId,
            $resolved,
            $allocSum,
            $whtMinor,
            $netMinor,
            $cashAccountCode,
            $currency,
            $memo,
            $actorId,
            $requestId,
        ) {
            $paymentId = Uuid::v7();
            $paymentNo = $this->nextNumber($companyId, date('Y-m-d'), 'supplier_payment');

            $payment = new SupplierPayment;
            $payment->id = $paymentId;
            $payment->company_id = $companyId;
            $payment->payment_no = $paymentNo;
            $payment->supplier_id = $supplierId;
            $payment->status = 'prepared';
            $payment->cash_account_code = $cashAccountCode;
            $payment->gross_minor = $allocSum;
            $payment->wht_minor = $whtMinor;
            $payment->net_minor = $netMinor;
            $payment->prepared_by = $actorId;
            $payment->memo = $memo;
            $payment->request_id = $requestId;
            $payment->save();

            foreach ($resolved as $alloc) {
                $row = new PaymentAllocation;
                $row->company_id = $companyId;
                $row->supplier_payment_id = $paymentId;
                $row->bill_id = $alloc['billId'];
                $row->amount_minor = $alloc['amountMinor'];
                $row->save();
            }

            $this->auditWriter->write(
                action: 'supplier_payment.prepared',
                entityType: 'supplier_payment',
                entityId: $paymentId,
                detail: [
                    'paymentNo' => $paymentNo,
                    'supplierId' => $supplierId,
                    'grossMinor' => $allocSum,
                    'netMinor' => $netMinor,
                ],
                actorId: $actorId,
            );

            return $this->toArray(
                SupplierPayment::query()->with('allocations')->findOrFail($paymentId)
            );
        });
    }

    private function nextNumber(string $companyId, string $date, string $docType): string
    {
        $year = substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = $docType;
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'SP';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('SP-%s-%05d', $year, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(SupplierPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'paymentNo' => $payment->payment_no,
            'supplierId' => $payment->supplier_id,
            'status' => $payment->status,
            'cashAccountCode' => $payment->cash_account_code,
            'grossMinor' => $payment->gross_minor,
            'whtMinor' => $payment->wht_minor,
            'netMinor' => $payment->net_minor,
            'bankRef' => $payment->bank_ref,
            'paymentDate' => $payment->payment_date?->format('Y-m-d'),
            'preparedBy' => $payment->prepared_by,
            'confirmedBy' => $payment->confirmed_by,
            'journalId' => $payment->journal_id,
            'memo' => $payment->memo,
            'allocations' => $payment->allocations->map(static fn (PaymentAllocation $a) => [
                'billId' => $a->bill_id,
                'amountMinor' => $a->amount_minor,
            ])->values()->all(),
            'createdAt' => $payment->created_at?->toIso8601String(),
        ];
    }
}
