<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

/**
 * GET /api/v1/reports — report library catalogue.
 * Matches the frontend report groups (Financial statements, Stock, Logistics,
 * Management, Tax) so the Reports hub can render without a hard-coded list.
 */
final class ReportCatalogueQuery
{
    /**
     * @return array{reports: list<array<string, string>>}
     */
    public function execute(): array
    {
        return [
            'reports' => [
                // Financial
                ['key' => 'trial_balance', 'title' => 'Trial balance', 'group' => 'Financial statements', 'description' => 'Posted balances by account with FS mapping.', 'path' => '/api/v1/reports/trial-balance'],
                ['key' => 'general_ledger', 'title' => 'General ledger', 'group' => 'Financial statements', 'description' => 'Posted lines between dates with running balance.', 'path' => '/api/v1/reports/general-ledger'],
                ['key' => 'profit_loss', 'title' => 'Profit and loss', 'group' => 'Financial statements', 'description' => 'Income statement for the period.', 'path' => '/api/v1/reports/profit_loss'],
                ['key' => 'balance_sheet', 'title' => 'Balance sheet', 'group' => 'Financial statements', 'description' => 'Statement of financial position as at a date.', 'path' => '/api/v1/reports/balance_sheet'],
                ['key' => 'ar_ageing_summary', 'title' => 'AR ageing summary', 'group' => 'Financial statements', 'description' => 'Receivables by ageing bucket.', 'path' => '/api/v1/reports/ar_ageing_summary'],
                ['key' => 'ap_ageing_summary', 'title' => 'AP ageing summary', 'group' => 'Financial statements', 'description' => 'Payables by ageing bucket.', 'path' => '/api/v1/reports/ap_ageing_summary'],
                // Stock
                ['key' => 'stock_valuation', 'title' => 'Stock valuation', 'group' => 'Stock', 'description' => 'Valuation by location and item.', 'path' => '/api/v1/reports/stock'],
                ['key' => 'stock_ageing', 'title' => 'Stock ageing & slow-moving', 'group' => 'Stock', 'description' => 'Age of lots and slow-moving signals.', 'path' => '/api/v1/reports/stock'],
                // Logistics
                ['key' => 'logistics_transit', 'title' => 'Transit performance', 'group' => 'Logistics', 'description' => 'Planned vs actual arrival by consignment.', 'path' => '/api/v1/reports/logistics'],
                // Management
                ['key' => 'management_headlines', 'title' => 'Management report', 'group' => 'Management', 'description' => 'Headlines and open exceptions.', 'path' => '/api/v1/reports/management'],
                // Performance
                ['key' => 'profit_by_product', 'title' => 'Profit by product', 'group' => 'Performance', 'description' => 'Margin by SKU.', 'path' => '/api/v1/reports/performance?view=profit_by_product'],
                ['key' => 'profit_by_customer', 'title' => 'Profit by customer', 'group' => 'Performance', 'description' => 'Margin by customer.', 'path' => '/api/v1/reports/performance?view=profit_by_customer'],
            ],
        ];
    }
}
