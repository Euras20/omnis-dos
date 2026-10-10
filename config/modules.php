<?php

declare(strict_types=1);

/**
 * Module discovery / enablement.
 *
 * All modules from Step 2 (01-domain-modules.md) are enabled by default.
 * Architecture: 04-folder-structure.md §4.3
 */

return [

    'enabled' => [
        'Identity',
        'Catalog',
        'Sales',
        'Procurement',
        'Inventory',
        'Logistics',
        'Fulfillment',
        'Quality',
        'Ledger',
        'Receivables',
        'Payables',
        'Treasury',
        'Tax',
        'Payroll',
        'Assets',
        'Profitability',
        'Reporting',
        'Controls',
        'Platform',
    ],

];
