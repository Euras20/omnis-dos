<?php

declare(strict_types=1);

namespace App\Modules\Payables\Queries;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Bill;
use App\Models\Party;
use App\Models\SupplierPayment;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/parties/{supplierId}/statement?from=&to=
 *
 * Supplier AP statement built only from posted bills and confirmed payments,
 * using the same bill_open() semantics as Payables balances.
 */
final class GetSupplierStatementQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function execute(string $supplierId, array $options = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ap.bills.view');

        $companyId = $this->companyContext->companyId();
        $from = (string) ($options['from'] ?? '');
        $to = (string) ($options['to'] ?? date('Y-m-d'));

        if ($from === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            throw new BusinessRuleException('from must be YYYY-MM-DD.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new BusinessRuleException('to must be YYYY-MM-DD.');
        }
        if ($from > $to) {
            throw new BusinessRuleException('from must be on or before to.');
        }

        $supplier = Party::query()
            ->where('company_id', $companyId)
            ->where('id', $supplierId)
            ->where('party_type', 'supplier')
            ->first();

        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        // Opening = open on all non-void bills as at day before period start.
        $openingCutoff = date('Y-m-d', strtotime($from.' -1 day'));
        $openingMinor = $this->sumOpenForSupplier($companyId, $supplierId, $openingCutoff);

        $bills = Bill::query()
            ->where('company_id', $companyId)
            ->where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->where('bill_date', '>=', $from)
            ->where('bill_date', '<=', $to)
            ->orderBy('bill_date')
            ->orderBy('bill_no')
            ->get();

        $payments = SupplierPayment::query()
            ->where('company_id', $companyId)
            ->where('supplier_id', $supplierId)
            ->where('status', 'confirmed')
            ->whereNotNull('payment_date')
            ->where('payment_date', '>=', $from)
            ->where('payment_date', '<=', $to)
            ->orderBy('payment_date')
            ->orderBy('payment_no')
            ->get();

        $lines = [];
        $billedMinor = 0;
        $paidMinor = 0;

        foreach ($bills as $bill) {
            $billedMinor += (int) $bill->gross_minor;
            $lines[] = [
                'date' => $bill->bill_date?->format('Y-m-d'),
                'type' => 'bill',
                'ref' => $bill->bill_no,
                'documentId' => $bill->id,
                'supplierRef' => $bill->supplier_ref,
                'debitMinor' => (int) $bill->gross_minor,
                'creditMinor' => 0,
                'memo' => $bill->memo,
            ];
        }

        foreach ($payments as $payment) {
            $paidMinor += (int) $payment->gross_minor;
            $lines[] = [
                'date' => $payment->payment_date?->format('Y-m-d'),
                'type' => 'payment',
                'ref' => $payment->payment_no,
                'documentId' => $payment->id,
                'supplierRef' => $payment->bank_ref,
                'debitMinor' => 0,
                'creditMinor' => (int) $payment->gross_minor,
                'memo' => $payment->memo,
            ];
        }

        usort($lines, static function (array $a, array $b): int {
            $cmp = strcmp((string) $a['date'], (string) $b['date']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['ref'], (string) $b['ref']);
        });

        $closingMinor = $this->sumOpenForSupplier($companyId, $supplierId, $to);

        return [
            'supplierId' => $supplier->id,
            'supplierCode' => $supplier->code,
            'supplierName' => $supplier->name,
            'from' => $from,
            'to' => $to,
            'openingMinor' => $openingMinor,
            'billedMinor' => $billedMinor,
            'paidMinor' => $paidMinor,
            'closingMinor' => $closingMinor,
            'lines' => $lines,
        ];
    }

    private function sumOpenForSupplier(string $companyId, string $supplierId, string $cutoff): int
    {
        $bills = Bill::query()
            ->where('company_id', $companyId)
            ->where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->where('bill_date', '<=', $cutoff)
            ->get(['id']);

        $total = 0;
        foreach ($bills as $bill) {
            $open = (int) DB::selectOne(
                'SELECT bill_open(?::uuid, ?::date) AS open',
                [$bill->id, $cutoff]
            )->open;
            $total += max(0, $open);
        }

        return $total;
    }
}
