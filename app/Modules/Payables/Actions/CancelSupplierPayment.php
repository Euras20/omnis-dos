<?php

declare(strict_types=1);

namespace App\Modules\Payables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\SupplierPayment;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/supplier-payments/{id}/cancel — cancel a prepared payment.
 */
final class CancelSupplierPayment
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $paymentId): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ap.payment.prepare');

        $companyId = $this->companyContext->companyId();

        $payment = SupplierPayment::query()
            ->where('company_id', $companyId)
            ->where('id', $paymentId)
            ->first();
        if ($payment === null) {
            throw new NotFoundException('Supplier payment not found.');
        }
        if ($payment->status !== 'prepared') {
            throw new BusinessRuleException("Only prepared payments can be cancelled (status is '{$payment->status}').");
        }

        return DB::transaction(function () use ($payment, $paymentId, $actorId) {
            $payment->status = 'cancelled';
            $payment->save();

            $this->auditWriter->write(
                action: 'supplier_payment.cancelled',
                entityType: 'supplier_payment',
                entityId: $paymentId,
                detail: ['paymentNo' => $payment->payment_no],
                actorId: $actorId,
            );

            return [
                'id' => $payment->id,
                'paymentNo' => $payment->payment_no,
                'status' => $payment->status,
            ];
        });
    }
}
