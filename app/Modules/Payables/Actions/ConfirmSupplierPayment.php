<?php

declare(strict_types=1);

namespace App\Modules\Payables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\SupplierPayment;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Payables\Services\AccountResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/supplier-payments/{id}/confirm — confirm payment (SoD + journal).
 */
final class ConfirmSupplierPayment
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly AccountResolver $accountResolver,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $paymentId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ap.payment.confirm');

        $companyId = $this->companyContext->companyId();
        $paymentDate = (string) ($data['paymentDate'] ?? $data['date'] ?? '');
        $bankRef = isset($data['bankRef']) ? trim((string) $data['bankRef']) : null;
        $requestId = $this->companyContext->requestId();

        if ($paymentDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            throw new BusinessRuleException('paymentDate must be YYYY-MM-DD.');
        }
        if ($bankRef === null || $bankRef === '') {
            throw new BusinessRuleException('bankRef is required to confirm a payment.');
        }

        $payment = SupplierPayment::query()
            ->where('company_id', $companyId)
            ->where('id', $paymentId)
            ->with('allocations')
            ->first();
        if ($payment === null) {
            throw new NotFoundException('Supplier payment not found.');
        }
        if ($payment->status !== 'prepared') {
            throw new BusinessRuleException("Payment is '{$payment->status}', expected 'prepared'.");
        }

        // SoD: confirmer ≠ preparer
        $this->permissionChecker->assertDifferentActors(
            $payment->prepared_by,
            $actorId,
            'Payment confirmer must differ from preparer.',
        );

        return DB::transaction(function () use (
            $companyId,
            $payment,
            $paymentId,
            $paymentDate,
            $bankRef,
            $actorId,
            $requestId,
        ) {
            $payment->status = 'confirmed';
            $payment->payment_date = $paymentDate;
            $payment->bank_ref = $bankRef;
            $payment->confirmed_by = $actorId;
            $payment->confirmed_at = now();

            // Journal: Dr AP (gross), Cr cash (net), Cr WHT payable (wht)
            $lines = [
                new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'ap'),
                    debitMinor: $payment->gross_minor,
                    creditMinor: 0,
                    partyId: $payment->supplier_id,
                    memo: "Payment {$payment->payment_no}",
                ),
            ];
            if ($payment->net_minor > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, $payment->cash_account_code ?? '1000'),
                    debitMinor: 0,
                    creditMinor: $payment->net_minor,
                    partyId: $payment->supplier_id,
                    memo: "Payment {$payment->payment_no}",
                );
            }
            if ($payment->wht_minor > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'wht_payable'),
                    debitMinor: 0,
                    creditMinor: $payment->wht_minor,
                    partyId: $payment->supplier_id,
                    memo: "WHT on payment {$payment->payment_no}",
                );
            }

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $paymentDate,
                postingKey: new PostingKey("supplier_payment:{$paymentId}:confirm"),
                sourceType: 'payment',
                sourceId: $paymentId,
                eventType: 'confirm',
                description: "Supplier payment {$payment->payment_no}",
                lines: $lines,
                actorId: $actorId,
                requestId: $requestId,
                preparedBy: $payment->prepared_by,
            ));

            $payment->journal_id = $journalId;
            $payment->save();

            $this->auditWriter->write(
                action: 'supplier_payment.confirmed',
                entityType: 'supplier_payment',
                entityId: $paymentId,
                detail: [
                    'paymentNo' => $payment->payment_no,
                    'bankRef' => $bankRef,
                    'grossMinor' => $payment->gross_minor,
                    'journalId' => $journalId,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'supplier_payment',
                'aggregate_id' => $paymentId,
                'event_type' => 'supplier_payment.confirmed',
                'payload' => [
                    'paymentId' => $paymentId,
                    'paymentNo' => $payment->payment_no,
                    'supplierId' => $payment->supplier_id,
                ],
            ]);

            return [
                'id' => $payment->id,
                'paymentNo' => $payment->payment_no,
                'supplierId' => $payment->supplier_id,
                'status' => $payment->status,
                'paymentDate' => $payment->payment_date?->format('Y-m-d'),
                'bankRef' => $payment->bank_ref,
                'grossMinor' => $payment->gross_minor,
                'whtMinor' => $payment->wht_minor,
                'netMinor' => $payment->net_minor,
                'preparedBy' => $payment->prepared_by,
                'confirmedBy' => $payment->confirmed_by,
                'confirmedAt' => $payment->confirmed_at?->toIso8601String(),
                'journalId' => $payment->journal_id,
            ];
        });
    }
}
