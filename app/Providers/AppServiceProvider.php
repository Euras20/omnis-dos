<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Idempotency\IdempotencyStore;
use App\Shared\Outbox\Handlers\DomainEventLogHandler;
use App\Shared\Outbox\Handlers\EmailSendHandler;
use App\Shared\Outbox\Handlers\PdfGenerateHandler;
use App\Shared\Outbox\OutboxDispatcher;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\PeriodLock\PeriodLockChecker;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Request-scoped company context (resolved by middleware later).
        $this->app->singleton(CompanyContext::class);

        $this->app->singleton(IdempotencyStore::class);
        $this->app->singleton(AuditWriter::class);
        $this->app->singleton(OutboxWriter::class);
        $this->app->singleton(OutboxDispatcher::class);
        $this->app->singleton(PeriodLockChecker::class);
        $this->app->singleton(PermissionChecker::class);
        $this->app->singleton(PostingService::class);

        $this->app->singleton(PdfGenerateHandler::class);
        $this->app->singleton(EmailSendHandler::class);
        $this->app->singleton(DomainEventLogHandler::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerOutboxHandlers();
    }

    /**
     * Map event_type → handler callable for the worker.
     * Handlers are idempotent; at-least-once delivery is expected.
     */
    private function registerOutboxHandlers(): void
    {
        /** @var OutboxDispatcher $dispatcher */
        $dispatcher = $this->app->make(OutboxDispatcher::class);

        $pdf = $this->app->make(PdfGenerateHandler::class);
        $email = $this->app->make(EmailSendHandler::class);
        $log = $this->app->make(DomainEventLogHandler::class);

        $dispatcher->register('document.pdf_requested', $pdf);
        $dispatcher->register('email.send', $email);

        // Domain notification-style events (structured log in this phase).
        foreach ([
            'invoice.posted',
            'receipt.posted',
            'credit_note.posted',
            'bill.posted',
            'supplier_payment.confirmed',
            'payroll.posted',
            'payroll.paid',
            'delivery.posted',
            'goods_receipt.posted',
            'purchase_order.approved',
            'purchase_order.created',
            'quotation.created',
            'quotation.updated',
            'quotation.sent',
            'sales_order.created_from_quotation',
            'sales_order.confirmed',
            'period.close_reminder',
            'depreciation.due_reminder',
            'report.export_requested',
        ] as $eventType) {
            $dispatcher->register($eventType, $log);
        }
    }
}
