<?php

declare(strict_types=1);

use App\Http\Controllers\Catalog\DocumentTemplateController;
use App\Http\Controllers\Catalog\FxRateController;
use App\Http\Controllers\Catalog\ItemCategoryController;
use App\Http\Controllers\Catalog\ItemController;
use App\Http\Controllers\Catalog\LocationController;
use App\Http\Controllers\Catalog\OrderTypeController;
use App\Http\Controllers\Catalog\PartyController;
use App\Http\Controllers\Catalog\TaxRuleController;
use App\Http\Controllers\Identity\Admin\AccessReviewController;
use App\Http\Controllers\Identity\Admin\PermissionController;
use App\Http\Controllers\Identity\Admin\RoleController;
use App\Http\Controllers\Identity\Admin\UserController;
use App\Http\Controllers\Identity\MeController;
use App\Http\Controllers\Ledger\AccountController;
use App\Http\Controllers\Ledger\JournalController;
use App\Http\Controllers\Inventory\DeliveryController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Payables\BillController;
use App\Http\Controllers\Payables\SupplierPaymentController;
use App\Http\Controllers\Procurement\GoodsReceiptController;
use App\Http\Controllers\Procurement\PurchaseOrderController;
use App\Http\Controllers\Procurement\RequisitionController;
use App\Http\Controllers\Procurement\RfqController;
use App\Http\Controllers\Receivables\InvoiceController;
use App\Http\Controllers\Receivables\ReceiptController;
use App\Http\Controllers\Sales\QuotationController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Payroll\EmployeeController;
use App\Http\Controllers\Payroll\PayrollRunController;
use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Treasury\ExpenseController;
use App\Http\Controllers\Treasury\TransferController;
use App\Http\Controllers\Platform\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1
|--------------------------------------------------------------------------
|
| All business routes live under the /api/v1 prefix.
| Module-specific route files may be required from here later.
|
| Architecture: 03-api.md, 04-folder-structure.md, 06-authn-authz.md
|
| Middleware stack (order):
|   ForceJsonResponse + EnsureRequestId   (api group prepend)
|   omnis.auth → omnis.company → omnis.idempotency  (authenticated group)
*/

Route::prefix('v1')->group(function () {

    // Public / unauthenticated endpoints
    Route::get('/health/live', fn () => response()->json(['status' => 'ok']));
    Route::get('/health/ready', fn () => response()->json(['status' => 'ok']));

    Route::get('/openapi.json', function () {
        $path = base_path('docs/openapi.json');
        if (! file_exists($path)) {
            return response()->json([
                'error' => 'openapi_not_generated',
                'message' => 'OpenAPI document has not been generated yet.',
            ], 404);
        }

        return response()->file($path, ['Content-Type' => 'application/json']);
    });

    // Authenticated business surface
    Route::middleware(['omnis.auth', 'omnis.company'])->group(function () {

        // --- Identity: session ---
        Route::get('/me', MeController::class);

        // --- Identity: administration (queries) ---
        Route::get('/admin/permissions', [PermissionController::class, 'index']);
        Route::get('/admin/roles', [RoleController::class, 'index']);
        Route::get('/admin/users', [UserController::class, 'index']);
        Route::get('/admin/users/{id}/effective', [UserController::class, 'effective']);
        Route::get('/admin/access-review', AccessReviewController::class);

        // --- Ledger: queries ---
        Route::get('/accounts', [AccountController::class, 'index']);
        Route::get('/journals', [JournalController::class, 'index']);
        Route::get('/journals/{id}', [JournalController::class, 'show']);

        // --- Catalog: queries ---
        Route::get('/parties', [PartyController::class, 'index']);
        Route::get('/parties/{id}/statement', [PartyController::class, 'statement']);
        Route::get('/parties/{id}', [PartyController::class, 'show']);
        Route::get('/locations', [LocationController::class, 'index']);
        Route::get('/item-categories', [ItemCategoryController::class, 'index']);
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/order-types', [OrderTypeController::class, 'index']);
        Route::get('/tax/rules', [TaxRuleController::class, 'index']);
        Route::get('/tax/wht', [TaxRuleController::class, 'wht']);
        Route::get('/fx/rates', [FxRateController::class, 'index']);
        Route::get('/document-templates', [DocumentTemplateController::class, 'index']);
        Route::get('/document-templates/{type}', [DocumentTemplateController::class, 'show']);

        // --- Receivables: queries ---
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::get('/receipts', [ReceiptController::class, 'index']);

        // --- Payables: queries ---
        Route::get('/bills', [BillController::class, 'index']);
        Route::get('/bills/{id}', [BillController::class, 'show']);
        Route::get('/supplier-payments', [SupplierPaymentController::class, 'index']);

        // --- Inventory / Stock: queries ---
        Route::get('/stock/on-hand', [StockController::class, 'onHand']);
        Route::get('/stock/assignments', [StockController::class, 'assignments']);
        Route::get('/stock/movements', [StockController::class, 'movements']);
        Route::get('/stock/ledger', [StockController::class, 'ledger']);
        Route::get('/stock/traceability/{lot}', [StockController::class, 'traceability']);
        Route::get('/stock/transfers', [StockController::class, 'transfers']);
        Route::get('/stock/counts', [StockController::class, 'counts']);
        Route::get('/stock/counts/{id}', [StockController::class, 'showCount']);
        Route::get('/tracking', [StockController::class, 'tracking']);

        // --- Sales: queries ---
        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::get('/quotations/{id}', [QuotationController::class, 'show']);
        Route::get('/sales-orders', [SalesOrderController::class, 'index']);
        Route::get('/sales-orders/{id}', [SalesOrderController::class, 'show']);

        // --- Procurement: queries ---
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
        Route::get('/purchase-orders/{id}/pdf', [PurchaseOrderController::class, 'pdf']);
        Route::get('/goods-receipts', [GoodsReceiptController::class, 'index']);
        Route::get('/goods-receipts/pending-qc', [GoodsReceiptController::class, 'pendingQc']);
        Route::get('/goods-receipts/{id}', [GoodsReceiptController::class, 'show']);
        Route::get('/requisitions', [RequisitionController::class, 'index']);
        Route::get('/rfqs', [RfqController::class, 'index']);

        // --- Documents ---
        Route::get('/documents/purchase-orders/{id}', [DocumentController::class, 'purchaseOrder']);

        // --- Payroll: queries ---
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::get('/payroll/runs', [PayrollRunController::class, 'index']);
        Route::get('/payroll/runs/{id}', [PayrollRunController::class, 'show']);

        // --- Assets: queries ---
        Route::get('/assets', [AssetController::class, 'index']);
        Route::get('/asset-classes', [AssetController::class, 'classes']);

        // --- Treasury: queries ---
        Route::get('/expenses', [ExpenseController::class, 'index']);

        // State-changing commands also require Idempotency-Key.
        Route::middleware(['omnis.idempotency'])->group(function () {
            // --- Identity: administration (commands) ---
            Route::post('/admin/roles', [RoleController::class, 'store']);
            Route::put('/admin/roles/{code}', [RoleController::class, 'update']);
            Route::delete('/admin/roles/{code}', [RoleController::class, 'destroy']);

            Route::post('/admin/users', [UserController::class, 'store']);
            Route::patch('/admin/users/{id}', [UserController::class, 'update']);
            Route::put('/admin/users/{id}/overrides', [UserController::class, 'replaceOverrides']);

            // --- Ledger: commands ---
            Route::post('/accounts', [AccountController::class, 'store']);
            Route::patch('/accounts/{id}', [AccountController::class, 'update']);
            Route::post('/journals', [JournalController::class, 'store']);
            Route::post('/journals/{id}/post', [JournalController::class, 'post']);
            Route::post('/journals/{id}/reverse', [JournalController::class, 'reverse']);

            // --- Catalog: commands ---
            Route::post('/parties', [PartyController::class, 'store']);
            Route::patch('/parties/{id}', [PartyController::class, 'update']);

            Route::post('/locations', [LocationController::class, 'store']);
            Route::delete('/locations/{id}', [LocationController::class, 'destroy']);

            Route::post('/item-categories', [ItemCategoryController::class, 'store']);
            Route::patch('/item-categories/{id}', [ItemCategoryController::class, 'update']);
            Route::post('/items', [ItemController::class, 'store']);
            Route::patch('/items/{id}', [ItemController::class, 'update']);
            Route::post('/order-types', [OrderTypeController::class, 'store']);
            Route::patch('/order-types/{id}', [OrderTypeController::class, 'update']);

            Route::post('/tax/rules', [TaxRuleController::class, 'store']);
            Route::put('/fx/rates/{date}', [FxRateController::class, 'upsert']);

            Route::put('/document-templates/{type}/draft', [DocumentTemplateController::class, 'saveDraft']);
            Route::delete('/document-templates/{type}/draft', [DocumentTemplateController::class, 'discardDraft']);
            Route::post('/document-templates/{type}/publish', [DocumentTemplateController::class, 'publish']);

            // --- Receivables: commands ---
            Route::post('/invoices', [InvoiceController::class, 'store']);
            Route::post('/invoices/{id}/credit-notes', [InvoiceController::class, 'storeCreditNote']);
            Route::post('/receipts', [ReceiptController::class, 'store']);
            Route::post('/receipts/{id}/apply', [ReceiptController::class, 'apply']);
            Route::post('/receipts/{id}/refunds', [ReceiptController::class, 'refund']);

            // --- Payables: commands ---
            Route::post('/bills', [BillController::class, 'store']);
            Route::post('/bills/{id}/resolve', [BillController::class, 'resolve']);
            Route::post('/supplier-payments', [SupplierPaymentController::class, 'store']);
            Route::post('/supplier-payments/{id}/confirm', [SupplierPaymentController::class, 'confirm']);
            Route::post('/supplier-payments/{id}/cancel', [SupplierPaymentController::class, 'cancel']);

            // --- Inventory / Stock: commands ---
            Route::post('/stock/assignments', [StockController::class, 'storeAssignment']);
            Route::post('/stock/assignments/{id}/decide', [StockController::class, 'decideAssignment']);
            Route::post('/stock/transfers', [StockController::class, 'storeTransfer']);
            Route::post('/stock/counts', [StockController::class, 'storeCount']);
            Route::post('/stock/counts/{id}/submit', [StockController::class, 'submitCount']);
            Route::post('/stock/counts/{id}/approve', [StockController::class, 'approveCount']);
            Route::post('/deliveries', [DeliveryController::class, 'store']);

            // --- Sales: commands ---
            Route::post('/quotations', [QuotationController::class, 'store']);
            Route::post('/quotations/{id}/status', [QuotationController::class, 'status']);
            Route::post('/quotations/{id}/convert', [QuotationController::class, 'convert']);
            Route::post('/sales-orders', [SalesOrderController::class, 'store']);
            Route::post('/sales-orders/{id}/confirm', [SalesOrderController::class, 'confirm']);

            // --- Procurement: commands ---
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::post('/purchase-orders/{id}/close', [PurchaseOrderController::class, 'close']);
            Route::post('/goods-receipts', [GoodsReceiptController::class, 'store']);
            Route::post('/qc/{id}', [GoodsReceiptController::class, 'qc']);
            Route::post('/requisitions', [RequisitionController::class, 'store']);
            Route::post('/requisitions/{id}/approve', [RequisitionController::class, 'approve']);
            Route::post('/requisitions/{id}/reject', [RequisitionController::class, 'reject']);
            Route::post('/requisitions/{id}/convert', [RequisitionController::class, 'convert']);
            Route::post('/rfqs', [RfqController::class, 'store']);
            Route::post('/rfqs/{id}/bids', [RfqController::class, 'storeBid']);
            Route::post('/rfqs/{id}/award', [RfqController::class, 'award']);

            // --- Payroll: commands ---
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::post('/payroll/runs', [PayrollRunController::class, 'store']);
            Route::post('/payroll/runs/{id}/approve', [PayrollRunController::class, 'approve']);
            Route::post('/payroll/runs/{id}/post', [PayrollRunController::class, 'post']);
            Route::post('/payroll/runs/{id}/pay', [PayrollRunController::class, 'pay']);

            // --- Assets: commands ---
            Route::post('/assets', [AssetController::class, 'store']);
            Route::post('/assets/depreciation/{month}', [AssetController::class, 'postDepreciation']);

            // --- Treasury: commands ---
            Route::post('/expenses', [ExpenseController::class, 'store']);
            Route::post('/transfers', [TransferController::class, 'store']);
        });
    });
});

