<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Domain;

/** Categories for entered landed costs. */
final class LandedCostCategory
{
    public const FREIGHT = 'Freight';

    public const INSURANCE = 'Insurance';

    public const DUTY = 'Duty';

    public const CLEARANCE = 'Clearance';

    public const SUPPLIER_PURCHASE = 'Supplier purchase';

    public const OTHER = 'Other';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::FREIGHT,
            self::INSURANCE,
            self::DUTY,
            self::CLEARANCE,
            self::SUPPLIER_PURCHASE,
            self::OTHER,
        ];
    }
}
